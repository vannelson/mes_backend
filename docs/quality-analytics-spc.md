# Quality Analytics and SPC Layer

This enhancement adds an analytics layer on top of the existing Quality Assurance & Reporting modules without changing the existing routes, menus, or CRUD ownership of the base modules.

## Scope

- Existing modules remain the system of record:
  - Quality Dashboard
  - Customer Quality
  - Supplier Quality
  - 8D / SCAR
  - VPD Claims
  - AOI Measurements
  - Calibration Master
  - Supplier Change Control
  - Operations Reports
- Analytics is additive and persisted in dedicated tables:
  - `quality_analytics_runs`
  - `quality_analytics_charts`
  - `quality_analytics_rule_violations`
  - `quality_analytics_source_links`

## Backend Flow

1. `GET /api/v1/quality/analytics`
   - Collects filtered quality data from the existing tables.
   - Builds an AOI/SPC payload only from numeric repeated measurements.
   - Runs a native Laravel/PHP analytics engine and persists the chart payloads and SPC results.
   - Persists chart metadata, capability metrics, rule violations, and source links.
2. Background refresh
   - `RecalculateQualityAnalyticsJob` is dispatched after AOI imports and after create/update/delete actions for quality issues, 8D reports, VPD claims, and AOI measurement deletions.

## AOI / SPC Logic

- SPC is only generated for numeric AOI detail rows.
- The selected characteristic comes from the `characteristic` filter or falls back to the most populated numeric characteristic.
- Current charts:
  - I-MR
  - Histogram
  - Boxplot
  - Run chart
  - Capability metric chart
  - Result distribution
  - X-bar / R when subgrouped repeated values are available
- Current rule detection:
  - Point outside control limits
  - Sustained upward trend
  - Sustained downward trend
  - Shift above centerline
  - Shift below centerline
  - Repeated near upper limit
  - Repeated near lower limit
  - Sudden process change
  - Abnormal moving range

## Dashboard Analytics

The persisted dashboard analytics currently include:

- Customer vs supplier issue trend
- Defect cause Pareto
- Machine quality ranking
- Operator quality ranking
- Calibration compliance
- VPD claim amount trend
- Supplier claim Pareto
- 8D / SCAR aging
- CAPA follow-up validation lots
- AOI pass / fail ratio

## Frontend Integration

- `QualityManagement.jsx`
  - Adds an analytics enhancement layer in the Dashboard tab.
- `AoiMeasurementsPage.jsx`
  - Adds persisted SPC widgets, capability KPIs, rule violations, and characteristic-aware filters.

## Environment Notes

- No external Python runtime is required.
- Persisted analytics now store chart payloads directly. If image artifacts are generated in future, they can still be attached to the same chart records.

## Traceability

- Every saved chart stores:
  - filter snapshot
  - raw series payload
  - calculated stats
  - file path
- AOI rule violations and dashboard groupings are linked back to source records through `quality_analytics_source_links`.
