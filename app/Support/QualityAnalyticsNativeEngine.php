<?php

namespace App\Support;

class QualityAnalyticsNativeEngine
{
    public function generate(array $payload): array
    {
        $dashboard = $this->buildDashboardCharts($payload);
        $aoiSpc = $this->buildSpcCharts($payload);

        return [
            'generated_at' => now()->toIso8601String(),
            'engine_name' => 'native-php-spc-engine',
            'engine_version' => '1.0.0',
            'summary_metrics' => $payload['summary_metrics'] ?? [],
            'capability_results' => ! empty($aoiSpc['capability']) ? [$aoiSpc['capability']] : [],
            'rule_summary' => collect($aoiSpc['rule_violations'] ?? [])->countBy('rule_code')->all(),
            'metadata' => [
                'filters' => $payload['filters'] ?? [],
                'dashboard_messages' => $dashboard['messages'] ?? [],
                'aoi_messages' => $aoiSpc['messages'] ?? [],
                'selected_characteristic' => $aoiSpc['selected_characteristic'] ?? null,
                'measurement_health' => $aoiSpc['measurement_health'] ?? [],
            ],
            'modules' => [
                'dashboard' => $dashboard,
                'aoi_spc' => $aoiSpc,
            ],
        ];
    }

    protected function buildDashboardCharts(array $payload): array
    {
        $dashboard = $payload['dashboard'] ?? [];

        return [
            'messages' => [],
            'charts' => [
                $this->chart('dashboard', 'quality_issue_trends', 'line', 'Customer vs Supplier Issue Trends', [
                    'entries' => $dashboard['issue_trends'] ?? [],
                    'value_keys' => ['customer_count', 'supplier_count'],
                ], [], false, $this->flattenSourceLinks($dashboard['issue_trends'] ?? [])),
                $this->chart('dashboard', 'quality_defect_pareto', 'pareto', 'Defect Cause Pareto', [
                    'entries' => $dashboard['defect_pareto'] ?? [],
                ], [], false, $this->flattenSourceLinks($dashboard['defect_pareto'] ?? [])),
                $this->chart('dashboard', 'machine_quality_ranking', 'bar', 'Machine Quality Ranking', [
                    'entries' => $dashboard['machine_rankings'] ?? [],
                    'value_key' => 'ng_count',
                    'horizontal' => true,
                ], [], false, $this->flattenSourceLinks($dashboard['machine_rankings'] ?? [])),
                $this->chart('dashboard', 'operator_quality_ranking', 'bar', 'Operator Quality Ranking', [
                    'entries' => $dashboard['operator_rankings'] ?? [],
                    'value_key' => 'ng_count',
                    'horizontal' => true,
                ], [], false, $this->flattenSourceLinks($dashboard['operator_rankings'] ?? [])),
                $this->chart('dashboard', 'calibration_compliance', 'pie', 'Calibration Compliance', [
                    'entries' => $dashboard['calibration_compliance'] ?? [],
                ], [], false, $this->flattenSourceLinks($dashboard['calibration_compliance'] ?? [])),
                $this->chart('dashboard', 'vpd_claim_trend', 'bar', 'VPD Claim Amount Trend', [
                    'entries' => $dashboard['vpd_claim_trends'] ?? [],
                    'value_key' => 'total_amount',
                ], [], false, $this->flattenSourceLinks($dashboard['vpd_claim_trends'] ?? [])),
                $this->chart('dashboard', 'supplier_claim_pareto', 'pareto', 'Supplier Claim Pareto', [
                    'entries' => $dashboard['supplier_claim_pareto'] ?? [],
                ], [], false, $this->flattenSourceLinks($dashboard['supplier_claim_pareto'] ?? [])),
                $this->chart('dashboard', 'capa_aging', 'bar', '8D / SCAR Aging', [
                    'entries' => $dashboard['capa_aging'] ?? [],
                    'value_key' => 'count',
                ], [], false, $this->flattenSourceLinks($dashboard['capa_aging'] ?? [])),
                $this->chart('dashboard', 'follow_up_validation', 'grouped_bar', 'CAPA Follow-up Validation Lots', [
                    'entries' => $dashboard['follow_up_validation'] ?? [],
                    'value_keys' => ['pass_count', 'fail_count'],
                ], [], false, $this->flattenSourceLinks($dashboard['follow_up_validation'] ?? [])),
                $this->chart('dashboard', 'aoi_pass_fail_ratio', 'pie', 'AOI Pass / Fail Ratio', [
                    'entries' => $dashboard['aoi_pass_fail'] ?? [],
                ], [], false, $this->flattenSourceLinks($dashboard['aoi_pass_fail'] ?? [])),
            ],
        ];
    }

    protected function buildSpcCharts(array $payload): array
    {
        $points = $payload['aoi_spc']['points'] ?? [];
        $characteristic = $payload['aoi_spc']['selected_characteristic'] ?? null;
        $validPoints = array_values(array_filter($points, fn (array $point) => $this->safeFloat($point['value'] ?? null) !== null));
        $invalidCount = count($points) - count($validPoints);
        $values = array_values(array_filter(array_map(fn (array $point) => $this->safeFloat($point['value'] ?? null), $validPoints), fn ($value) => $value !== null));
        $messages = [];

        if (count($values) < 2) {
            return [
                'selected_characteristic' => $characteristic,
                'messages' => ['The selected AOI characteristic does not have enough numeric repeated measurements for SPC.'],
                'charts' => [
                    $this->chart('aoi_spc', 'aoi_spc_unsuitable', 'message', 'AOI SPC Suitability', [], [
                        'message' => 'The selected AOI characteristic does not have enough numeric repeated measurements for SPC.',
                    ], true),
                ],
                'capability' => [],
                'measurement_health' => [
                    'data_point_count' => count($points),
                    'valid_numeric_count' => count($values),
                    'invalid_value_count' => $invalidCount,
                    'out_of_spec_count' => count(array_filter($points, fn (array $point) => (bool) ($point['is_out_of_spec'] ?? false))),
                    'out_of_control_count' => 0,
                ],
                'rule_violations' => [],
            ];
        }

        $limits = $this->calculateControlLimits($values);
        $lsl = $this->firstNumericValue(array_map(fn (array $point) => $point['lsl'] ?? null, $validPoints));
        $usl = $this->firstNumericValue(array_map(fn (array $point) => $point['usl'] ?? null, $validPoints));
        $nominal = $this->firstNumericValue(array_map(fn (array $point) => $point['nominal'] ?? null, $validPoints));
        $capability = $this->calculateCapability($values, $lsl, $usl, $limits);
        $violations = $this->detectRules($validPoints, $values, $limits);
        $movingRanges = [];
        for ($index = 1; $index < count($values); $index++) {
            $movingRanges[] = abs($values[$index] - $values[$index - 1]);
        }
        $labels = array_map(fn (array $point, int $index) => $this->timestampLabel($point['measurement_time'] ?? null) ?: (string) $index, $validPoints, range(1, count($validPoints)));
        $sourceLinks = $this->uniqueLinks(array_map(fn (array $point) => [
            'source_module' => 'aoi_measurements',
            'source_type' => 'detail',
            'source_id' => (int) ($point['detail_id'] ?? 0),
            'metadata' => [
                'header_id' => $point['header_id'] ?? null,
                'characteristic_code' => $point['characteristic_code'] ?? null,
            ],
        ], array_filter($validPoints, fn (array $point) => ! empty($point['detail_id']))));

        $histogram = $this->histogramSeries($values);
        $boxplot = $this->boxplotSeries($values);
        $distribution = collect($validPoints)
            ->groupBy(fn (array $point) => $point['result_status'] ?: 'Unknown')
            ->map(fn ($group, $label) => ['label' => $label, 'count' => count($group)])
            ->values()
            ->all();
        $subgroups = $this->subgroupSeries($validPoints);
        if (empty($subgroups)) {
            $messages[] = 'Subgroup SPC charts were skipped because no subgroup with repeated measurements was available.';
        }

        $charts = [
            $this->chart('aoi_spc', 'aoi_i_mr_chart', 'i_mr', 'I-MR Chart', [
                'values' => $values,
                'labels' => $labels,
                'moving_ranges' => $movingRanges,
            ], array_merge($limits, $capability, [
                'characteristic' => $characteristic,
                'lsl' => $lsl,
                'usl' => $usl,
                'nominal' => $nominal,
            ]), true, $sourceLinks, $violations),
            $this->chart('aoi_spc', 'aoi_histogram', 'histogram', 'Measurement Histogram', $histogram, array_merge($capability, [
                'characteristic' => $characteristic,
                'lsl' => $lsl,
                'usl' => $usl,
                'nominal' => $nominal,
            ]), true, $sourceLinks),
            $this->chart('aoi_spc', 'aoi_boxplot', 'boxplot', 'Measurement Boxplot', $boxplot, array_merge($capability, [
                'characteristic' => $characteristic,
            ]), true, $sourceLinks),
            $this->chart('aoi_spc', 'aoi_run_chart', 'run_chart', 'Measurement Run Chart', [
                'values' => $values,
                'labels' => $labels,
            ], array_merge($limits, ['characteristic' => $characteristic]), true, $sourceLinks),
            $this->chart('aoi_spc', 'aoi_capability_chart', 'capability', 'Process Capability Metrics', [
                'metrics' => [
                    ['label' => 'Cp', 'value' => $capability['cp'] ?? null],
                    ['label' => 'Cpk', 'value' => $capability['cpk'] ?? null],
                    ['label' => 'Pp', 'value' => $capability['pp'] ?? null],
                    ['label' => 'Ppk', 'value' => $capability['ppk'] ?? null],
                    ['label' => 'Sigma', 'value' => $capability['sigma_level'] ?? null],
                ],
            ], array_merge($capability, ['characteristic' => $characteristic]), true, $sourceLinks),
            $this->chart('aoi_spc', 'aoi_result_distribution', 'pie', 'Result Distribution', [
                'entries' => $distribution,
            ], ['characteristic' => $characteristic], true, $sourceLinks),
        ];

        if (! empty($subgroups)) {
            $charts[] = $this->chart('aoi_spc', 'aoi_xbar_r_chart', 'xbar_r', 'X-bar / R Chart', [
                'groups' => $subgroups,
            ], ['characteristic' => $characteristic, 'group_count' => count($subgroups)], true, $this->uniqueLinks(array_merge(...array_map(fn (array $group) => $group['source_links'], $subgroups))));
        }

        return [
            'selected_characteristic' => $characteristic,
            'messages' => $messages,
            'charts' => $charts,
            'capability' => $capability,
            'measurement_health' => [
                'data_point_count' => count($points),
                'valid_numeric_count' => count($values),
                'invalid_value_count' => $invalidCount,
                'out_of_spec_count' => count(array_filter($validPoints, fn (array $point) => (bool) ($point['is_out_of_spec'] ?? false))),
                'out_of_control_count' => count(array_filter($violations, fn (array $violation) => ($violation['rule_code'] ?? '') === 'POINT_OUTSIDE_CONTROL_LIMIT')),
            ],
            'rule_violations' => $violations,
        ];
    }

    protected function chart(
        string $moduleKey,
        string $chartKey,
        string $chartType,
        string $title,
        array $seriesPayload = [],
        array $statPayload = [],
        bool $isSpc = false,
        array $sourceLinks = [],
        array $ruleViolations = []
    ): array {
        return [
            'module_key' => $moduleKey,
            'chart_key' => $chartKey,
            'chart_type' => $chartType,
            'title' => $title,
            'filename' => null,
            'is_spc' => $isSpc,
            'series_payload' => $seriesPayload,
            'stat_payload' => $statPayload,
            'metadata' => [],
            'rule_violations' => $ruleViolations,
            'source_links' => $sourceLinks,
        ];
    }

    protected function flattenSourceLinks(array $entries): array
    {
        $links = [];
        foreach ($entries as $entry) {
            foreach (($entry['source_links'] ?? []) as $link) {
                $links[] = $link;
            }
        }

        return $this->uniqueLinks($links);
    }

    protected function uniqueLinks(array $links): array
    {
        $seen = [];
        $output = [];
        foreach ($links as $link) {
            if (empty($link['source_id'])) {
                continue;
            }
            $key = sprintf('%s|%s|%s', $link['source_module'] ?? 'module', $link['source_type'] ?? 'record', $link['source_id']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $output[] = $link;
        }

        return $output;
    }

    protected function firstNumericValue(array $values): ?float
    {
        foreach ($values as $value) {
            $numeric = $this->safeFloat($value);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return null;
    }

    protected function safeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected function mean(array $values): float
    {
        return array_sum($values) / max(count($values), 1);
    }

    protected function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sum = array_sum(array_map(fn (float $value) => ($value - $mean) ** 2, $values));

        return sqrt($sum / ($count - 1));
    }

    protected function calculateControlLimits(array $values): array
    {
        if (count($values) < 2) {
            return [];
        }

        $movingRanges = [];
        for ($index = 1; $index < count($values); $index++) {
            $movingRanges[] = abs($values[$index] - $values[$index - 1]);
        }
        $mean = $this->mean($values);
        $mrBar = ! empty($movingRanges) ? $this->mean($movingRanges) : 0.0;
        $sigmaWithin = $mrBar > 0 ? $mrBar / 1.128 : $this->standardDeviation($values);

        return [
            'mean' => $mean,
            'mr_bar' => $mrBar,
            'sigma_within' => $sigmaWithin,
            'ucl' => $mean + (3 * $sigmaWithin),
            'lcl' => $mean - (3 * $sigmaWithin),
            'mr_ucl' => $mrBar * 3.267,
        ];
    }

    protected function calculateCapability(array $values, ?float $lsl, ?float $usl, array $limits): array
    {
        if (count($values) < 2) {
            return [];
        }

        $mean = $this->mean($values);
        $stdOverall = $this->standardDeviation($values);
        $stdWithin = $this->safeFloat($limits['sigma_within'] ?? null) ?: $stdOverall;

        $cp = $cpk = $pp = $ppk = $sigmaLevel = null;
        if ($stdWithin > 0 && $lsl !== null && $usl !== null) {
            $cp = ($usl - $lsl) / (6 * $stdWithin);
            $cpk = min(($usl - $mean) / (3 * $stdWithin), ($mean - $lsl) / (3 * $stdWithin));
        }
        if ($stdOverall > 0 && $lsl !== null && $usl !== null) {
            $pp = ($usl - $lsl) / (6 * $stdOverall);
            $ppk = min(($usl - $mean) / (3 * $stdOverall), ($mean - $lsl) / (3 * $stdOverall));
        }
        if ($cpk !== null) {
            $sigmaLevel = $cpk * 3;
        }

        return [
            'count' => count($values),
            'mean' => $mean,
            'std_dev' => $stdOverall,
            'cp' => $cp,
            'cpk' => $cpk,
            'pp' => $pp,
            'ppk' => $ppk,
            'sigma_level' => $sigmaLevel,
            'min' => min($values),
            'max' => max($values),
        ];
    }

    protected function detectRules(array $points, array $values, array $limits): array
    {
        if (count($values) < 2 || empty($limits)) {
            return [];
        }

        $mean = $limits['mean'];
        $sigma = $limits['sigma_within'] ?? 0.0;
        $ucl = $limits['ucl'];
        $lcl = $limits['lcl'];
        $violations = [];

        foreach ($values as $index => $value) {
            $point = $points[$index];
            if ($value > $ucl || $value < $lcl) {
                $violations[] = [
                    'rule_code' => 'POINT_OUTSIDE_CONTROL_LIMIT',
                    'severity' => 'high',
                    'message' => sprintf('Point %d is outside the control limits.', $index + 1),
                    'context' => [
                        'point_index' => $index + 1,
                        'value' => $value,
                        'ucl' => $ucl,
                        'lcl' => $lcl,
                        'detail_id' => $point['detail_id'] ?? null,
                        'header_id' => $point['header_id'] ?? null,
                    ],
                ];
            }
        }

        for ($start = 0; $start <= max(count($values) - 6, 0); $start++) {
            $window = array_slice($values, $start, 6);
            if ($this->isStrictTrend($window, 'up')) {
                $violations[] = ['rule_code' => 'SUSTAINED_UPWARD_TREND', 'severity' => 'medium', 'message' => sprintf('Six consecutive points are trending upward starting at point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 6]];
            }
            if ($this->isStrictTrend($window, 'down')) {
                $violations[] = ['rule_code' => 'SUSTAINED_DOWNWARD_TREND', 'severity' => 'medium', 'message' => sprintf('Six consecutive points are trending downward starting at point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 6]];
            }
        }

        for ($start = 0; $start <= max(count($values) - 8, 0); $start++) {
            $window = array_slice($values, $start, 8);
            if (count(array_filter($window, fn (float $value) => $value > $mean)) === 8) {
                $violations[] = ['rule_code' => 'SHIFT_ABOVE_CENTERLINE', 'severity' => 'medium', 'message' => sprintf('Eight consecutive points are above the center line starting at point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 8]];
            }
            if (count(array_filter($window, fn (float $value) => $value < $mean)) === 8) {
                $violations[] = ['rule_code' => 'SHIFT_BELOW_CENTERLINE', 'severity' => 'medium', 'message' => sprintf('Eight consecutive points are below the center line starting at point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 8]];
            }
        }

        if ($sigma > 0) {
            $threshold = $sigma * 2;
            for ($start = 0; $start <= max(count($values) - 3, 0); $start++) {
                $window = array_slice($values, $start, 3);
                if (count(array_filter($window, fn (float $value) => $value > ($mean + $threshold))) >= 2) {
                    $violations[] = ['rule_code' => 'REPEATED_NEAR_UPPER_LIMIT', 'severity' => 'medium', 'message' => sprintf('Two of three points are near the upper process limit around point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 3]];
                }
                if (count(array_filter($window, fn (float $value) => $value < ($mean - $threshold))) >= 2) {
                    $violations[] = ['rule_code' => 'REPEATED_NEAR_LOWER_LIMIT', 'severity' => 'medium', 'message' => sprintf('Two of three points are near the lower process limit around point %d.', $start + 1), 'context' => ['start_index' => $start + 1, 'end_index' => $start + 3]];
                }
            }
        }

        return $this->uniqueRuleViolations($violations);
    }

    protected function uniqueRuleViolations(array $violations): array
    {
        $seen = [];
        $output = [];
        foreach ($violations as $violation) {
            $key = ($violation['rule_code'] ?? 'UNKNOWN') . '|' . json_encode($violation['context'] ?? []);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $output[] = $violation;
        }

        return $output;
    }

    protected function isStrictTrend(array $values, string $direction): bool
    {
        for ($index = 0; $index < count($values) - 1; $index++) {
            if ($direction === 'up' && $values[$index] >= $values[$index + 1]) {
                return false;
            }
            if ($direction === 'down' && $values[$index] <= $values[$index + 1]) {
                return false;
            }
        }

        return true;
    }

    protected function histogramSeries(array $values): array
    {
        $bins = max(5, min(12, (int) ceil(sqrt(max(count($values), 1)))));
        $min = min($values);
        $max = max($values);
        $range = max($max - $min, 0.0001);
        $binWidth = $range / $bins;
        $entries = [];
        for ($index = 0; $index < $bins; $index++) {
            $start = $min + ($index * $binWidth);
            $end = $start + $binWidth;
            $entries[] = ['label' => sprintf('%.2f-%.2f', $start, $end), 'count' => 0];
        }
        foreach ($values as $value) {
            $index = min($bins - 1, (int) floor(($value - $min) / $binWidth));
            $entries[$index]['count']++;
        }

        return ['entries' => $entries];
    }

    protected function boxplotSeries(array $values): array
    {
        sort($values);
        return [
            'min' => min($values),
            'q1' => $this->percentile($values, 25),
            'median' => $this->percentile($values, 50),
            'q3' => $this->percentile($values, 75),
            'max' => max($values),
        ];
    }

    protected function percentile(array $values, float $percent): float
    {
        sort($values);
        $count = count($values);
        if ($count === 1) {
            return (float) $values[0];
        }

        $index = ($percent / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        $weight = $index - $lower;
        return ((1 - $weight) * $values[$lower]) + ($weight * $values[$upper]);
    }

    protected function subgroupSeries(array $points): array
    {
        $groups = [];
        foreach ($points as $point) {
            $groups[$point['subgroup_key'] ?? 'Ungrouped'][] = $point;
        }

        $usable = [];
        foreach ($groups as $label => $members) {
            $values = array_values(array_filter(array_map(fn (array $member) => $this->safeFloat($member['value'] ?? null), $members), fn ($value) => $value !== null));
            if (count($values) < 2) {
                continue;
            }
            $usable[] = [
                'label' => (string) $label,
                'mean' => $this->mean($values),
                'range' => max($values) - min($values),
                'count' => count($values),
                'source_links' => $this->uniqueLinks(array_map(fn (array $member) => [
                    'source_module' => 'aoi_measurements',
                    'source_type' => 'detail',
                    'source_id' => (int) ($member['detail_id'] ?? 0),
                    'metadata' => [],
                ], array_filter($members, fn (array $member) => ! empty($member['detail_id'])))),
            ];
        }

        usort($usable, fn (array $left, array $right) => strcmp($left['label'], $right['label']));
        return array_slice($usable, 0, 25);
    }

    protected function timestampLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i', $timestamp) : (string) $value;
    }
}
