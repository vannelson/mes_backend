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
  const current = getValueByPath(snapshot, fieldPath);
  const previous = beforeSnapshot ? getValueByPath(beforeSnapshot, fieldPath) : null;

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

exports.scheduledTriggerSweep = functions.pubsub
  .schedule("every 5 minutes")
  .timeZone("Asia/Singapore")
  .onRun(async () => {
    const now = new Date().toISOString();
    await rtdb.ref("mes/triggers/schedules/last_run").set({ at: now });
    return null;
  });
