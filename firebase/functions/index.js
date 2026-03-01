const functions = require("firebase-functions");
const admin = require("firebase-admin");

admin.initializeApp();

const firestore = admin.firestore();
const rtdb = admin.database();

const resolveTriggersRef = (tenantId) =>
  firestore.collection("tenants").doc(tenantId).collection("operation_triggers");

const evaluateOperator = (operator, actual, expected, expectedTo) => {
  const toNumber = (value) => (value === null || value === "" ? 0 : Number(value));
  const normalize = (value) => `${value ?? ""}`.toLowerCase();
  const compare = (left, right) => normalize(left) === normalize(right);

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
    case "gt":
      return toNumber(actual) > toNumber(expected);
    case "gte":
      return toNumber(actual) >= toNumber(expected);
    case "lt":
      return toNumber(actual) < toNumber(expected);
    case "lte":
      return toNumber(actual) <= toNumber(expected);
    case "between":
      return (
        toNumber(actual) >= toNumber(expected) &&
        toNumber(actual) <= toNumber(expectedTo)
      );
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
  assignee: "metadata.state.assignee",
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

const evaluateCondition = (condition, snapshot, changeList) => {
  const fieldKey = condition.field || condition.path;
  const fieldPath = condition.path || FIELD_MAP[fieldKey] || fieldKey;
  const operator = condition.operator || "eq";
  const expected = condition.value;
  const expectedTo = condition.valueTo;
  const current = fieldPath
    ? fieldPath.split(".").reduce((acc, key) => acc?.[key], snapshot)
    : null;

  if (["changed", "changed_to", "changed_from"].includes(operator)) {
    const changed =
      changeList?.includes(fieldKey) || changeList?.includes(fieldPath);
    if (!changed) return false;
    if (operator === "changed") return true;
    if (operator === "changed_to") return evaluateOperator("eq", current, expected);
    return false;
  }

  return evaluateOperator(operator, current, expected, expectedTo);
};

const evaluateGroup = (group, snapshot, changeList) => {
  const gate = (group.gate || "all").toLowerCase();
  const conditions = group.conditions || [];
  const groups = group.groups || [];

  const conditionResults = conditions.map((condition) =>
    evaluateCondition(condition, snapshot, changeList)
  );
  const groupResults = groups.map((child) => evaluateGroup(child, snapshot, changeList));
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
    const changeList = payload.changed_fields || [];

    const triggersSnapshot = await resolveTriggersRef(tenantId)
      .where("status", "==", "published")
      .where("is_active", "==", true)
      .get();

    const tasks = [];
    triggersSnapshot.forEach((doc) => {
      const trigger = doc.data();
      const rule = trigger.rule || {};
      const matched = evaluateGroup(rule, workOrderSnapshot, changeList);
      if (!matched) return;

      tasks.push(
        rtdb.ref("mes/triggers/executions").push({
          trigger_id: doc.id,
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
