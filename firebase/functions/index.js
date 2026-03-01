const functions = require("firebase-functions");
const admin = require("firebase-admin");

admin.initializeApp();

const rtdb = admin.database();

const toNumber = (value) => (value === null || value === "" ? 0 : Number(value));
const normalize = (value) => `${value ?? ""}`.toLowerCase();
const compare = (left, right) => normalize(left) === normalize(right);
const getValueByPath = (source, path) =>
  path ? path.split(".").reduce((acc, key) => acc?.[key], source) : null;
const isDateValue = (value) => {
  if (value === null || value === "") return false;
  return !Number.isNaN(Date.parse(value));
};
const parseDate = (value) => Date.parse(value);

const isIn = (actual, expected) => {
  const expectedList = Array.isArray(expected)
    ? expected.map((entry) => normalize(entry))
    : `${expected ?? ""}`
        .split(",")
        .map((entry) => normalize(entry.trim()))
        .filter(Boolean);

  if (Array.isArray(actual)) {
    const actualList = actual.map((entry) => normalize(entry));
    return actualList.some((entry) => expectedList.includes(entry));
  }

  return expectedList.includes(normalize(actual));
};

const evaluateOperator = (operator, actual, expected, expectedTo) => {
  switch (operator) {
    case "eq":
      return compare(actual, expected);
    case "neq":
      return !compare(actual, expected);
    case "contains":
      return normalize(actual).includes(normalize(expected));
    case "starts_with":
      return normalize(actual).startsWith(normalize(expected));
    case "ends_with":
      return normalize(actual).endsWith(normalize(expected));
    case "in":
      return isIn(actual, expected);
    case "not_in":
      return !isIn(actual, expected);
    case "gt":
      return toNumber(actual) > toNumber(expected);
    case "gte":
      return toNumber(actual) >= toNumber(expected);
    case "lt":
      return toNumber(actual) < toNumber(expected);
    case "lte":
      return toNumber(actual) <= toNumber(expected);
    case "between":
      if (isDateValue(actual) && (isDateValue(expected) || isDateValue(expectedTo))) {
        const actualDate = parseDate(actual);
        return actualDate >= parseDate(expected) && actualDate <= parseDate(expectedTo);
      }
      return (
        toNumber(actual) >= toNumber(expected) &&
        toNumber(actual) <= toNumber(expectedTo)
      );
    case "before":
      return isDateValue(actual) && isDateValue(expected)
        ? parseDate(actual) < parseDate(expected)
        : false;
    case "after":
      return isDateValue(actual) && isDateValue(expected)
        ? parseDate(actual) > parseDate(expected)
        : false;
    case "within_last":
      if (!isDateValue(actual)) return false;
      return parseDate(actual) >= Date.now() - toNumber(expected) * 60000;
    case "true":
      return Boolean(actual) === true;
    case "false":
      return Boolean(actual) === false;
    default:
      return false;
  }
};

const extractRoutesFromMetadata = (metadata = {}) => {
  const routes = metadata?.routes ?? metadata?.data ?? metadata?.steps ?? [];
  if (!Array.isArray(routes)) return [];
  const flattened = [];
  routes.forEach((entry) => {
    if (!entry || typeof entry !== "object") return;
    if (Array.isArray(entry.routes)) {
      entry.routes.forEach((route) => {
        if (route && typeof route === "object") flattened.push(route);
      });
      return;
    }
    flattened.push(entry);
  });
  return flattened;
};

const resolveRouteTimeTracker = (route = {}) => {
  const metadata = route?.metadata && typeof route.metadata === "object" ? route.metadata : {};
  const tracker =
    metadata?.timeTracker ||
    metadata?.time_tracker ||
    route?.timeTracker ||
    route?.time_tracker ||
    {};
  return tracker && typeof tracker === "object" ? tracker : {};
};

const computeProgressPctFromMetadata = (metadata = {}) => {
  const routes = extractRoutesFromMetadata(metadata);
  if (!routes.length) return null;
  let best = null;

  routes.forEach((route) => {
    if (!route || typeof route !== "object") return;
    const tracker = resolveRouteTimeTracker(route);
    const entries = Array.isArray(tracker.entries) ? tracker.entries : [];
    entries.forEach((entry) => {
      if (!entry || typeof entry !== "object") return;
      const value =
        entry.route_progress_pct ??
        entry.routeProgressPct ??
        entry.operator_progress_pct ??
        entry.operatorProgressPct ??
        null;
      if (value === null || value === undefined) return;
      const numeric = toNumber(value);
      if (best === null || numeric > best) best = numeric;
    });

    if (best === null && entries.length) {
      const produced = entries.reduce((max, entry) => {
        if (!entry || typeof entry !== "object") return max;
        const value =
          entry.total_printed_qty ??
          entry.totalPrintedQty ??
          entry.printed_qty ??
          entry.printedQty ??
          null;
        if (value === null || value === undefined) return max;
        const numeric = toNumber(value);
        return max === null || numeric > max ? numeric : max;
      }, null);
      const target = entries.reduce((max, entry) => {
        if (!entry || typeof entry !== "object") return max;
        const value = entry.target_printed_qty ?? entry.targetPrintedQty ?? null;
        if (value === null || value === undefined) return max;
        const numeric = toNumber(value);
        return max === null || numeric > max ? numeric : max;
      }, null);
      const fallbackTarget = target ?? toNumber(metadata?.state?.qty ?? 0);
      if (produced !== null && fallbackTarget) {
        const candidate = Math.max(0, Math.min(1, produced / fallbackTarget) * 100);
        if (best === null || candidate > best) best = candidate;
      }
    }
  });

  return best;
};

const FIELD_MAP = {
  status: "status",
  priority: "priority",
  assignee: "metadata.state.assignees",
  team: "metadata.state.team",
  sla_timer: "metadata.sla.minutes",
  sla_breach: "metadata.sla.breached",
  validation_result: "metadata.validation.result",
  checklist_packing: "metadata.checklists.packing.completed",
  checklist_quality: "metadata.checklists.quality.completed",
  progress_pct: "metadata.state.progressPct",
  parameter_temp: "metadata.parameters.temperature",
  updated_at: "updated_at",
  custom_field: "metadata.custom",
};

const evaluateCondition = (condition, snapshot, changeList, beforeSnapshot) => {
  const fieldKey = condition.field || condition.path;
  const fieldPath = condition.path || FIELD_MAP[fieldKey] || fieldKey;
  const operator = condition.operator || "eq";
  const expected = condition.value;
  const expectedTo = condition.valueTo;
  let current = getValueByPath(snapshot, fieldPath);
  const previous = beforeSnapshot ? getValueByPath(beforeSnapshot, fieldPath) : null;

  if (current === null && fieldKey === "progress_pct") {
    const computed = computeProgressPctFromMetadata(snapshot?.metadata || {});
    if (computed !== null) {
      current = computed;
    }
  }

  if (["changed", "changed_to", "changed_from"].includes(operator)) {
    const changed = beforeSnapshot
      ? !compare(previous, current)
      : changeList?.includes(fieldKey) || changeList?.includes(fieldPath);
    if (!changed) return false;
    if (operator === "changed") return true;
    if (operator === "changed_to") return evaluateOperator("eq", current, expected);
    if (!beforeSnapshot) return false;
    return evaluateOperator("eq", previous, expected);
  }

  return evaluateOperator(operator, current, expected, expectedTo);
};

const evaluateGroup = (group, snapshot, changeList, beforeSnapshot) => {
  const gate = (group.gate || "all").toLowerCase();
  const conditions = group.conditions || [];
  const groups = group.groups || [];

  const conditionResults = conditions.map((condition) =>
    evaluateCondition(condition, snapshot, changeList, beforeSnapshot)
  );
  const groupResults = groups.map((child) =>
    evaluateGroup(child, snapshot, changeList, beforeSnapshot)
  );
  const allResults = [...conditionResults, ...groupResults];

  if (!allResults.length) return false;

  if (gate === "any") {
    return allResults.some(Boolean);
  }
  return allResults.every(Boolean);
};

exports.onWorkOrderEvent = functions.database
  .ref("mes/workorders/events/{eventId}")
  .onCreate(async (snapshot) => {
    const payload = snapshot.val() || {};
    const tenantId = payload.tenant_id || "default";
    const workOrderSnapshot = payload.snapshot || {};
    const beforeSnapshot = payload.before_snapshot || null;
    const changeList = payload.changed_fields || [];

    const triggersSnapshot = await rtdb.ref("mes/triggers/definitions").once("value");
    const definitions = triggersSnapshot.val() || {};

    const tasks = [];
    Object.entries(definitions).forEach(([triggerId, trigger]) => {
      if (!trigger || typeof trigger !== "object") return;
      if ((trigger.tenant_id || "default") !== tenantId) return;
      if (trigger.status !== "published" || trigger.is_active !== true) return;
      const rule = trigger.rule || {};
      const matched = evaluateGroup(rule, workOrderSnapshot, changeList, beforeSnapshot);
      if (!matched) return;

      tasks.push(
        rtdb.ref("mes/triggers/executions").push({
          trigger_id: triggerId,
          work_order_id: payload.work_order_id,
          status: "queued",
          event_id: payload.event_id,
          queued_at: new Date().toISOString(),
        })
      );
    });

    await Promise.all(tasks);
    return null;
  });

exports.onTriggerExecutionQueued = functions.database
  .ref("mes/triggers/executions/{executionId}")
  .onCreate(async (snapshot, context) => {
    const execution = snapshot.val() || {};
    const triggerId = execution.trigger_id;
    if (!triggerId) {
      return null;
    }

    await snapshot.ref.update({
      status: "processing",
      started_at: new Date().toISOString(),
    });

    const apiBase =
      process.env.MES_API_BASE_URL || functions.config()?.mes?.api_base_url;
    const triggerKey =
      process.env.MES_TRIGGER_KEY || functions.config()?.mes?.trigger_key;

    if (!apiBase || !triggerKey) {
      await snapshot.ref.update({
        status: "failed",
        error: "Missing MES_API_BASE_URL or MES_TRIGGER_KEY.",
        finished_at: new Date().toISOString(),
      });
      return null;
    }

    const url = `${apiBase.replace(/\\/$/, "")}/api/v1/operation-triggers/${triggerId}/execute-internal`;

    try {
      const response = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Trigger-Key": triggerKey,
        },
        body: JSON.stringify({
          execution_id: context.params.executionId,
          work_order_id: execution.work_order_id || null,
          work_order_no: execution.work_order_no || null,
          event_id: execution.event_id || null,
        }),
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(payload?.message || "Execution request failed.");
      }

      await snapshot.ref.update({
        status: payload?.data?.status || "success",
        finished_at: new Date().toISOString(),
      });
    } catch (error) {
      await snapshot.ref.update({
        status: "failed",
        error: error?.message || "Execution failed.",
        finished_at: new Date().toISOString(),
      });
    }

    return null;
  });

exports.scheduledTriggerSweep = functions.pubsub
  .schedule("every 5 minutes")
  .timeZone("Asia/Singapore")
  .onRun(async () => {
    const now = new Date().toISOString();
    await rtdb.ref("mes/triggers/schedules/last_run").set({ at: now });
    return null;
  });
