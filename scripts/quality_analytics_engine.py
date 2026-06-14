#!/usr/bin/env python3
import json
import math
import os
import sys
from collections import Counter, defaultdict
from datetime import datetime

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np


def read_json(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def ensure_dir(path):
    os.makedirs(path, exist_ok=True)


def safe_float(value):
    try:
        if value is None or value == "":
            return None
        return float(value)
    except Exception:
        return None


def json_ready(value):
    if isinstance(value, dict):
        return {str(k): json_ready(v) for k, v in value.items()}
    if isinstance(value, list):
        return [json_ready(v) for v in value]
    if isinstance(value, (np.floating, np.integer)):
        return value.item()
    return value


def timestamp_label(value):
    if not value:
        return ""
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).strftime("%Y-%m-%d %H:%M")
    except Exception:
        return str(value)


def create_chart_base(title, chart_key, chart_type, filename, module_key, is_spc=False):
    return {
        "module_key": module_key,
        "chart_key": chart_key,
        "chart_type": chart_type,
        "title": title,
        "filename": filename,
        "is_spc": is_spc,
        "series_payload": {},
        "stat_payload": {},
        "metadata": {},
        "rule_violations": [],
        "source_links": [],
    }


def save_figure(fig, output_dir, filename):
    ensure_dir(output_dir)
    path = os.path.join(output_dir, filename)
    fig.tight_layout()
    fig.savefig(path, dpi=180, bbox_inches="tight")
    plt.close(fig)
    return filename


def unique_links(links):
    seen = set()
    output = []
    for link in links:
        key = (
            link.get("source_module"),
            link.get("source_type"),
            link.get("source_id"),
        )
        if key in seen:
            continue
        seen.add(key)
        output.append(link)
    return output


def build_source_links(entries, source_module, source_type, metadata=None):
    metadata = metadata or {}
    return [
        {
            "source_module": source_module,
            "source_type": source_type,
            "source_id": int(entry),
            "metadata": metadata,
        }
        for entry in entries
        if entry is not None
    ]


def calc_control_limits(values):
    if len(values) < 2:
        return None
    mean = float(np.mean(values))
    moving_ranges = [abs(values[index] - values[index - 1]) for index in range(1, len(values))]
    mr_bar = float(np.mean(moving_ranges)) if moving_ranges else 0.0
    sigma_within = mr_bar / 1.128 if mr_bar else float(np.std(values, ddof=1)) if len(values) > 1 else 0.0
    ucl = mean + 3 * sigma_within
    lcl = mean - 3 * sigma_within
    mr_ucl = mr_bar * 3.267
    return {
        "mean": mean,
        "mr_bar": mr_bar,
        "sigma_within": sigma_within,
        "ucl": ucl,
        "lcl": lcl,
        "mr_ucl": mr_ucl,
    }


def calc_capability(values, lsl, usl):
    if len(values) < 2:
        return {}
    mean = float(np.mean(values))
    std_overall = float(np.std(values, ddof=1)) if len(values) > 1 else 0.0
    limits = calc_control_limits(values) or {}
    std_within = safe_float(limits.get("sigma_within")) or std_overall
    cp = cpk = pp = ppk = sigma_level = None
    if std_within and lsl is not None and usl is not None:
        cp = (usl - lsl) / (6 * std_within)
        cpk = min((usl - mean) / (3 * std_within), (mean - lsl) / (3 * std_within))
    if std_overall and lsl is not None and usl is not None:
        pp = (usl - lsl) / (6 * std_overall)
        ppk = min((usl - mean) / (3 * std_overall), (mean - lsl) / (3 * std_overall))
    if cpk is not None:
        sigma_level = cpk * 3
    return {
        "count": len(values),
        "mean": mean,
        "std_dev": std_overall,
        "cp": cp,
        "cpk": cpk,
        "pp": pp,
        "ppk": ppk,
        "sigma_level": sigma_level,
        "min": float(np.min(values)),
        "max": float(np.max(values)),
    }


def detect_rules(points, values, limits):
    if not limits:
        return []
    mean = limits["mean"]
    sigma = limits["sigma_within"] or 0.0
    ucl = limits["ucl"]
    lcl = limits["lcl"]
    violations = []

    for idx, value in enumerate(values):
        point = points[idx]
        if value > ucl or value < lcl:
            violations.append(
                {
                    "rule_code": "POINT_OUTSIDE_CONTROL_LIMIT",
                    "severity": "high",
                    "message": f"Point {idx + 1} is outside the control limits.",
                    "context": {
                        "point_index": idx + 1,
                        "value": value,
                        "ucl": ucl,
                        "lcl": lcl,
                        "detail_id": point.get("detail_id"),
                        "header_id": point.get("header_id"),
                    },
                }
            )

    for start in range(0, max(len(values) - 5, 0)):
        window = values[start : start + 6]
        if all(window[index] < window[index + 1] for index in range(len(window) - 1)):
            violations.append(
                {
                    "rule_code": "SUSTAINED_UPWARD_TREND",
                    "severity": "medium",
                    "message": f"Six consecutive points are trending upward starting at point {start + 1}.",
                    "context": {"start_index": start + 1, "end_index": start + 6},
                }
            )
        if all(window[index] > window[index + 1] for index in range(len(window) - 1)):
            violations.append(
                {
                    "rule_code": "SUSTAINED_DOWNWARD_TREND",
                    "severity": "medium",
                    "message": f"Six consecutive points are trending downward starting at point {start + 1}.",
                    "context": {"start_index": start + 1, "end_index": start + 6},
                }
            )

    for start in range(0, max(len(values) - 7, 0)):
        window = values[start : start + 8]
        if all(value > mean for value in window):
            violations.append(
                {
                    "rule_code": "SHIFT_ABOVE_CENTERLINE",
                    "severity": "medium",
                    "message": f"Eight consecutive points are above the center line starting at point {start + 1}.",
                    "context": {"start_index": start + 1, "end_index": start + 8},
                }
            )
        if all(value < mean for value in window):
            violations.append(
                {
                    "rule_code": "SHIFT_BELOW_CENTERLINE",
                    "severity": "medium",
                    "message": f"Eight consecutive points are below the center line starting at point {start + 1}.",
                    "context": {"start_index": start + 1, "end_index": start + 8},
                }
            )

    if sigma > 0:
        threshold = sigma * 2
        for start in range(0, max(len(values) - 2, 0)):
            window = values[start : start + 3]
            above = [value for value in window if value > (mean + threshold)]
            below = [value for value in window if value < (mean - threshold)]
            if len(above) >= 2:
                violations.append(
                    {
                        "rule_code": "REPEATED_NEAR_UPPER_LIMIT",
                        "severity": "medium",
                        "message": f"Two of three points are near the upper process limit around point {start + 1}.",
                        "context": {"start_index": start + 1, "end_index": start + 3},
                    }
                )
            if len(below) >= 2:
                violations.append(
                    {
                        "rule_code": "REPEATED_NEAR_LOWER_LIMIT",
                        "severity": "medium",
                        "message": f"Two of three points are near the lower process limit around point {start + 1}.",
                        "context": {"start_index": start + 1, "end_index": start + 3},
                    }
                )

        for index in range(1, len(values)):
            delta = abs(values[index] - values[index - 1])
            if delta > sigma * 3:
                violations.append(
                    {
                        "rule_code": "SUDDEN_PROCESS_CHANGE",
                        "severity": "medium",
                        "message": f"A sudden process change was detected between points {index} and {index + 1}.",
                        "context": {
                            "point_index": index + 1,
                            "previous_value": values[index - 1],
                            "current_value": values[index],
                            "delta": delta,
                        },
                    }
                )

    moving_ranges = [abs(values[index] - values[index - 1]) for index in range(1, len(values))]
    mr_ucl = limits.get("mr_ucl")
    if mr_ucl:
        for index, mr in enumerate(moving_ranges, start=2):
            if mr > mr_ucl:
                violations.append(
                    {
                        "rule_code": "ABNORMAL_MOVING_RANGE",
                        "severity": "medium",
                        "message": f"Moving range exceeded the upper limit at point {index}.",
                        "context": {"point_index": index, "moving_range": mr, "mr_ucl": mr_ucl},
                    }
                )

    deduped = []
    seen = set()
    for violation in violations:
        key = (violation["rule_code"], json.dumps(violation["context"], sort_keys=True))
        if key in seen:
            continue
        seen.add(key)
        deduped.append(violation)
    return deduped


def chart_placeholder(output_dir, module_key, chart_key, title, message):
    fig, ax = plt.subplots(figsize=(8, 3.8))
    ax.axis("off")
    ax.text(
        0.5,
        0.6,
        title,
        ha="center",
        va="center",
        fontsize=14,
        fontweight="bold",
    )
    ax.text(0.5, 0.35, message, ha="center", va="center", fontsize=10, wrap=True)
    filename = save_figure(fig, output_dir, f"{chart_key}.png")
    chart = create_chart_base(title, chart_key, "message", filename, module_key, False)
    chart["metadata"]["message"] = message
    return chart


def build_bar_chart(output_dir, module_key, chart_key, title, entries, value_key="count", color="#0f766e", horizontal=False):
    if not entries:
        return chart_placeholder(output_dir, module_key, chart_key, title, "No data available for the selected filters.")
    labels = [entry.get("label", "Unspecified") for entry in entries]
    values = [safe_float(entry.get(value_key)) or 0 for entry in entries]
    fig, ax = plt.subplots(figsize=(9, 4.5))
    if horizontal:
        ax.barh(labels, values, color=color)
        ax.invert_yaxis()
    else:
        ax.bar(labels, values, color=color)
        ax.tick_params(axis="x", rotation=30)
    ax.set_title(title)
    ax.grid(axis="y", linestyle="--", alpha=0.25)
    filename = save_figure(fig, output_dir, f"{chart_key}.png")
    chart = create_chart_base(title, chart_key, "bar", filename, module_key, False)
    chart["series_payload"] = {"entries": entries}
    chart["stat_payload"] = {
        "value_key": value_key,
        "total": sum(values),
        "top_label": labels[0] if labels else None,
    }
    source_links = []
    for entry in entries:
        source_links.extend(entry.get("source_links", []))
    chart["source_links"] = unique_links(source_links)
    return chart


def build_line_chart(output_dir, module_key, chart_key, title, entries, value_keys, colors):
    if not entries:
        return chart_placeholder(output_dir, module_key, chart_key, title, "No time-series data available for the selected filters.")
    labels = [entry.get("period", "Unknown") for entry in entries]
    fig, ax = plt.subplots(figsize=(9, 4.5))
    for index, key in enumerate(value_keys):
        ax.plot(labels, [safe_float(entry.get(key)) or 0 for entry in entries], marker="o", label=key.replace("_", " ").title(), color=colors[index % len(colors)])
    ax.set_title(title)
    ax.legend()
    ax.grid(axis="y", linestyle="--", alpha=0.25)
    ax.tick_params(axis="x", rotation=30)
    filename = save_figure(fig, output_dir, f"{chart_key}.png")
    chart = create_chart_base(title, chart_key, "line", filename, module_key, False)
    chart["series_payload"] = {"entries": entries, "value_keys": value_keys}
    source_links = []
    for entry in entries:
        source_links.extend(entry.get("source_links", []))
    chart["source_links"] = unique_links(source_links)
    return chart


def build_pareto_chart(output_dir, module_key, chart_key, title, entries):
    if not entries:
        return chart_placeholder(output_dir, module_key, chart_key, title, "No Pareto data available for the selected filters.")
    labels = [entry.get("label", "Unspecified") for entry in entries]
    counts = np.array([safe_float(entry.get("count")) or 0 for entry in entries])
    cumulative = counts.cumsum()
    total = counts.sum() or 1
    cumulative_pct = cumulative / total * 100
    fig, ax1 = plt.subplots(figsize=(9, 4.8))
    ax1.bar(labels, counts, color="#dc2626", alpha=0.85)
    ax1.set_ylabel("Count")
    ax1.set_title(title)
    ax1.tick_params(axis="x", rotation=30)
    ax1.grid(axis="y", linestyle="--", alpha=0.25)
    ax2 = ax1.twinx()
    ax2.plot(labels, cumulative_pct, color="#0f766e", marker="o")
    ax2.set_ylabel("Cumulative %")
    ax2.set_ylim(0, 110)
    filename = save_figure(fig, output_dir, f"{chart_key}.png")
    chart = create_chart_base(title, chart_key, "pareto", filename, module_key, False)
    chart["series_payload"] = {"entries": entries}
    chart["stat_payload"] = {"total": float(total)}
    source_links = []
    for entry in entries:
        source_links.extend(entry.get("source_links", []))
    chart["source_links"] = unique_links(source_links)
    return chart


def build_pie_chart(output_dir, module_key, chart_key, title, entries):
    if not entries:
        return chart_placeholder(output_dir, module_key, chart_key, title, "No distribution data available for the selected filters.")
    labels = [entry.get("label", "Unspecified") for entry in entries]
    values = [safe_float(entry.get("count")) or 0 for entry in entries]
    fig, ax = plt.subplots(figsize=(7, 4.6))
    ax.pie(values, labels=labels, autopct="%1.1f%%", startangle=90)
    ax.set_title(title)
    filename = save_figure(fig, output_dir, f"{chart_key}.png")
    chart = create_chart_base(title, chart_key, "pie", filename, module_key, False)
    chart["series_payload"] = {"entries": entries}
    source_links = []
    for entry in entries:
        source_links.extend(entry.get("source_links", []))
    chart["source_links"] = unique_links(source_links)
    return chart


def subgroup_series(points):
    buckets = defaultdict(list)
    for point in points:
        key = point.get("subgroup_key") or "Ungrouped"
        buckets[key].append(point)
    usable = []
    for key, members in buckets.items():
        values = [safe_float(member.get("value")) for member in members]
        values = [value for value in values if value is not None]
        if len(values) >= 2:
            usable.append((key, members, values))
    usable.sort(key=lambda row: row[0])
    return usable[:25]


def build_spc_charts(output_dir, payload):
    module_key = "aoi_spc"
    charts = []
    points = payload.get("aoi_spc", {}).get("points", [])
    messages = []
    characteristic = payload.get("aoi_spc", {}).get("selected_characteristic")

    valid_points = [point for point in points if safe_float(point.get("value")) is not None]
    invalid_count = len(points) - len(valid_points)
    values = [safe_float(point.get("value")) for point in valid_points]
    values = [value for value in values if value is not None]

    if not values or len(values) < 2:
        messages.append("The selected AOI characteristic does not have enough numeric repeated measurements for SPC.")
        charts.append(
            chart_placeholder(
                output_dir,
                module_key,
                "aoi_spc_unsuitable",
                "AOI SPC Suitability",
                messages[0],
            )
        )
        return {
            "selected_characteristic": characteristic,
            "messages": messages,
            "charts": charts,
            "capability": {},
            "measurement_health": {
                "data_point_count": len(points),
                "valid_numeric_count": len(values),
                "invalid_value_count": invalid_count,
                "out_of_spec_count": sum(1 for point in points if point.get("is_out_of_spec")),
                "out_of_control_count": 0,
            },
            "rule_violations": [],
        }

    lsl_candidates = [safe_float(point.get("lsl")) for point in valid_points if safe_float(point.get("lsl")) is not None]
    usl_candidates = [safe_float(point.get("usl")) for point in valid_points if safe_float(point.get("usl")) is not None]
    nominal_candidates = [safe_float(point.get("nominal")) for point in valid_points if safe_float(point.get("nominal")) is not None]
    lsl = lsl_candidates[0] if lsl_candidates else None
    usl = usl_candidates[0] if usl_candidates else None
    nominal = nominal_candidates[0] if nominal_candidates else None

    limits = calc_control_limits(values)
    capability = calc_capability(values, lsl, usl)
    violations = detect_rules(valid_points, values, limits)
    source_links = unique_links(
        [
            {
                "source_module": "aoi_measurements",
                "source_type": "detail",
                "source_id": int(point.get("detail_id")),
                "metadata": {
                    "header_id": point.get("header_id"),
                    "characteristic_code": point.get("characteristic_code"),
                },
            }
            for point in valid_points
            if point.get("detail_id") is not None
        ]
    )

    x_labels = list(range(1, len(values) + 1))
    display_labels = [timestamp_label(point.get("measurement_time")) or str(index) for index, point in enumerate(valid_points, start=1)]

    fig, axes = plt.subplots(2, 1, figsize=(11, 7), sharex=True)
    axes[0].plot(x_labels, values, marker="o", color="#0f766e")
    axes[0].axhline(limits["mean"], color="#1d4ed8", linestyle="--", label="Center line")
    axes[0].axhline(limits["ucl"], color="#dc2626", linestyle="--", label="UCL")
    axes[0].axhline(limits["lcl"], color="#dc2626", linestyle="--", label="LCL")
    if lsl is not None:
        axes[0].axhline(lsl, color="#f59e0b", linestyle=":", label="LSL")
    if usl is not None:
        axes[0].axhline(usl, color="#f59e0b", linestyle=":", label="USL")
    axes[0].set_title(f"I-MR Chart - {characteristic or 'Characteristic'}")
    axes[0].grid(alpha=0.25, linestyle="--")
    axes[0].legend(loc="upper right", fontsize=8)
    moving_ranges = [abs(values[index] - values[index - 1]) for index in range(1, len(values))]
    axes[1].plot(list(range(2, len(values) + 1)), moving_ranges, marker="o", color="#7c3aed")
    axes[1].axhline(limits["mr_bar"], color="#1d4ed8", linestyle="--", label="MR avg")
    axes[1].axhline(limits["mr_ucl"], color="#dc2626", linestyle="--", label="MR UCL")
    axes[1].set_xticks(x_labels[: min(len(x_labels), 15)])
    axes[1].set_xticklabels(display_labels[: min(len(display_labels), 15)], rotation=35, ha="right", fontsize=7)
    axes[1].grid(alpha=0.25, linestyle="--")
    axes[1].legend(loc="upper right", fontsize=8)
    filename = save_figure(fig, output_dir, "aoi_i_mr_chart.png")
    chart = create_chart_base("I-MR Chart", "aoi_i_mr_chart", "i_mr", filename, module_key, True)
    chart["series_payload"] = {"values": values, "labels": display_labels, "moving_ranges": moving_ranges}
    chart["stat_payload"] = {**limits, **capability}
    chart["metadata"] = {"characteristic": characteristic, "nominal": nominal, "lsl": lsl, "usl": usl}
    chart["source_links"] = source_links
    chart["rule_violations"] = violations
    charts.append(chart)

    fig, ax = plt.subplots(figsize=(9, 4.8))
    ax.hist(values, bins=min(12, max(5, int(math.sqrt(len(values))))), color="#0ea5e9", edgecolor="white", alpha=0.9)
    if nominal is not None:
        ax.axvline(nominal, color="#1d4ed8", linestyle="--", label="Nominal")
    if lsl is not None:
        ax.axvline(lsl, color="#dc2626", linestyle=":", label="LSL")
    if usl is not None:
        ax.axvline(usl, color="#dc2626", linestyle=":", label="USL")
    ax.axvline(float(np.mean(values)), color="#0f766e", linestyle="-.", label="Mean")
    ax.set_title("Measurement Histogram")
    ax.grid(alpha=0.2, linestyle="--")
    ax.legend(fontsize=8)
    filename = save_figure(fig, output_dir, "aoi_histogram.png")
    chart = create_chart_base("Measurement Histogram", "aoi_histogram", "histogram", filename, module_key, True)
    chart["series_payload"] = {"values": values}
    chart["stat_payload"] = capability
    chart["metadata"] = {"characteristic": characteristic}
    chart["source_links"] = source_links
    charts.append(chart)

    fig, ax = plt.subplots(figsize=(8.5, 4.2))
    ax.boxplot(values, vert=False, patch_artist=True, boxprops={"facecolor": "#10b981"})
    ax.set_title("Measurement Boxplot")
    ax.grid(alpha=0.2, linestyle="--")
    filename = save_figure(fig, output_dir, "aoi_boxplot.png")
    chart = create_chart_base("Measurement Boxplot", "aoi_boxplot", "boxplot", filename, module_key, True)
    chart["series_payload"] = {"values": values}
    chart["stat_payload"] = capability
    chart["metadata"] = {"characteristic": characteristic}
    chart["source_links"] = source_links
    charts.append(chart)

    fig, ax = plt.subplots(figsize=(10.5, 4.6))
    ax.plot(x_labels, values, marker="o", color="#0f766e")
    ax.axhline(limits["mean"], color="#1d4ed8", linestyle="--")
    ax.set_title("Measurement Run Chart")
    ax.set_xticks(x_labels[: min(len(x_labels), 15)])
    ax.set_xticklabels(display_labels[: min(len(display_labels), 15)], rotation=35, ha="right", fontsize=7)
    ax.grid(alpha=0.2, linestyle="--")
    filename = save_figure(fig, output_dir, "aoi_run_chart.png")
    chart = create_chart_base("Measurement Run Chart", "aoi_run_chart", "run_chart", filename, module_key, True)
    chart["series_payload"] = {"values": values, "labels": display_labels}
    chart["stat_payload"] = limits
    chart["metadata"] = {"characteristic": characteristic}
    chart["source_links"] = source_links
    charts.append(chart)

    metrics_to_show = [
        ("Cp", capability.get("cp")),
        ("Cpk", capability.get("cpk")),
        ("Pp", capability.get("pp")),
        ("Ppk", capability.get("ppk")),
        ("Sigma", capability.get("sigma_level")),
    ]
    fig, ax = plt.subplots(figsize=(8.2, 4.8))
    labels = [label for label, value in metrics_to_show]
    metric_values = [value if value is not None else 0 for _, value in metrics_to_show]
    ax.bar(labels, metric_values, color=["#0f766e", "#1d4ed8", "#8b5cf6", "#f97316", "#dc2626"])
    ax.set_title("Process Capability Metrics")
    ax.grid(axis="y", linestyle="--", alpha=0.25)
    filename = save_figure(fig, output_dir, "aoi_capability_chart.png")
    chart = create_chart_base("Process Capability Metrics", "aoi_capability_chart", "capability", filename, module_key, True)
    chart["series_payload"] = {"metrics": [{"label": label, "value": value} for label, value in metrics_to_show]}
    chart["stat_payload"] = capability
    chart["metadata"] = {"characteristic": characteristic}
    chart["source_links"] = source_links
    charts.append(chart)

    result_counts = Counter(point.get("result_status") or "Unknown" for point in valid_points)
    fig, ax = plt.subplots(figsize=(7.4, 4.6))
    ax.pie(result_counts.values(), labels=result_counts.keys(), autopct="%1.1f%%", startangle=90)
    ax.set_title("Result Distribution")
    filename = save_figure(fig, output_dir, "aoi_result_distribution.png")
    chart = create_chart_base("Result Distribution", "aoi_result_distribution", "distribution", filename, module_key, True)
    chart["series_payload"] = {"entries": [{"label": label, "count": count} for label, count in result_counts.items()]}
    chart["metadata"] = {"characteristic": characteristic}
    chart["source_links"] = source_links
    charts.append(chart)

    usable_subgroups = subgroup_series(valid_points)
    if usable_subgroups:
        subgroup_labels = [row[0] for row in usable_subgroups]
        subgroup_means = [float(np.mean(row[2])) for row in usable_subgroups]
        subgroup_ranges = [max(row[2]) - min(row[2]) for row in usable_subgroups]
        fig, axes = plt.subplots(2, 1, figsize=(10.5, 6.8), sharex=True)
        axes[0].plot(subgroup_labels, subgroup_means, marker="o", color="#1d4ed8")
        axes[0].set_title("X-bar Chart")
        axes[0].grid(alpha=0.25, linestyle="--")
        axes[1].plot(subgroup_labels, subgroup_ranges, marker="o", color="#dc2626")
        axes[1].set_title("R Chart")
        axes[1].grid(alpha=0.25, linestyle="--")
        axes[1].tick_params(axis="x", rotation=35)
        filename = save_figure(fig, output_dir, "aoi_xbar_r_chart.png")
        group_links = []
        for _, members, _values in usable_subgroups:
            group_links.extend(
                build_source_links(
                    [member.get("detail_id") for member in members if member.get("detail_id") is not None],
                    "aoi_measurements",
                    "detail",
                )
            )
        chart = create_chart_base("X-bar / R Chart", "aoi_xbar_r_chart", "xbar_r", filename, module_key, True)
        chart["series_payload"] = {
            "groups": [
                {
                    "label": subgroup_labels[index],
                    "mean": subgroup_means[index],
                    "range": subgroup_ranges[index],
                    "count": len(usable_subgroups[index][2]),
                }
                for index in range(len(subgroup_labels))
            ]
        }
        chart["metadata"] = {"characteristic": characteristic, "group_count": len(usable_subgroups)}
        chart["source_links"] = unique_links(group_links)
        charts.append(chart)
    else:
        messages.append("Subgroup SPC charts were skipped because no subgroup with repeated measurements was available.")

    out_of_control_count = sum(1 for violation in violations if violation["rule_code"] == "POINT_OUTSIDE_CONTROL_LIMIT")
    return {
        "selected_characteristic": characteristic,
        "messages": messages,
        "charts": charts,
        "capability": capability,
        "measurement_health": {
            "data_point_count": len(points),
            "valid_numeric_count": len(values),
            "invalid_value_count": invalid_count,
            "out_of_spec_count": sum(1 for point in valid_points if point.get("is_out_of_spec")),
            "out_of_control_count": out_of_control_count,
        },
        "rule_violations": violations,
    }


def build_dashboard_charts(output_dir, payload):
    module_key = "dashboard"
    dashboard = payload.get("dashboard", {})
    charts = []
    charts.append(
        build_line_chart(
            output_dir,
            module_key,
            "quality_issue_trends",
            "Customer vs Supplier Issue Trends",
            dashboard.get("issue_trends", []),
            ["customer_count", "supplier_count"],
            ["#0f766e", "#dc2626"],
        )
    )
    charts.append(
        build_pareto_chart(
            output_dir,
            module_key,
            "quality_defect_pareto",
            "Defect Cause Pareto",
            dashboard.get("defect_pareto", []),
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "machine_quality_ranking",
            "Machine Quality Ranking",
            dashboard.get("machine_rankings", []),
            value_key="ng_count",
            color="#dc2626",
            horizontal=True,
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "operator_quality_ranking",
            "Operator Quality Ranking",
            dashboard.get("operator_rankings", []),
            value_key="ng_count",
            color="#2563eb",
            horizontal=True,
        )
    )
    charts.append(
        build_pie_chart(
            output_dir,
            module_key,
            "calibration_compliance",
            "Calibration Compliance",
            dashboard.get("calibration_compliance", []),
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "vpd_claim_trend",
            "VPD Claim Amount Trend",
            dashboard.get("vpd_claim_trends", []),
            value_key="total_amount",
            color="#7c3aed",
        )
    )
    charts.append(
        build_pareto_chart(
            output_dir,
            module_key,
            "supplier_claim_pareto",
            "Supplier Claim Pareto",
            dashboard.get("supplier_claim_pareto", []),
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "capa_aging",
            "8D / SCAR Aging",
            dashboard.get("capa_aging", []),
            value_key="count",
            color="#f97316",
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "follow_up_validation",
            "CAPA Follow-up Validation Lots",
            dashboard.get("follow_up_validation", []),
            value_key="pass_count",
            color="#16a34a",
        )
    )
    charts.append(
        build_bar_chart(
            output_dir,
            module_key,
            "aoi_pass_fail_ratio",
            "AOI Pass / Fail Ratio",
            dashboard.get("aoi_pass_fail", []),
            value_key="count",
            color="#0ea5e9",
        )
    )
    return {
        "messages": [],
        "charts": charts,
        "metrics": payload.get("summary_metrics", {}),
    }


def main():
    if len(sys.argv) < 3:
        raise SystemExit("Usage: quality_analytics_engine.py <input_json> <output_dir>")

    input_path = sys.argv[1]
    output_dir = sys.argv[2]
    ensure_dir(output_dir)

    payload = read_json(input_path)
    dashboard = build_dashboard_charts(output_dir, payload)
    aoi_spc = build_spc_charts(output_dir, payload)

    rule_summary_counter = Counter()
    for violation in aoi_spc.get("rule_violations", []):
        rule_summary_counter[violation["rule_code"]] += 1

    result = {
        "generated_at": datetime.utcnow().isoformat() + "Z",
        "engine_name": "matplotlib-spc-engine",
        "engine_version": "1.0.0",
        "summary_metrics": payload.get("summary_metrics", {}),
        "capability_results": [aoi_spc.get("capability", {})] if aoi_spc.get("capability") else [],
        "rule_summary": dict(rule_summary_counter),
        "metadata": {
            "filters": payload.get("filters", {}),
            "dashboard_messages": dashboard.get("messages", []),
            "aoi_messages": aoi_spc.get("messages", []),
            "selected_characteristic": aoi_spc.get("selected_characteristic"),
            "measurement_health": aoi_spc.get("measurement_health", {}),
        },
        "modules": {
            "dashboard": dashboard,
            "aoi_spc": aoi_spc,
        },
    }

    print(json.dumps(json_ready(result)))


if __name__ == "__main__":
    main()
