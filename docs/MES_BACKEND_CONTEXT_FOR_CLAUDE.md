# MES Backend Context For Claude

This document summarizes the current `mes_backend` repository as inspected on 2026-06-23. It is intended as a high-signal handoff for another AI assistant. It avoids secret values from `.env` files.

## One-Line Summary

`mes_backend` is a Laravel 12 manufacturing execution system backend with a large `/api/v1` JSON API, Sanctum auth, Fortify/Inertia web auth, Vue 3 frontend assets, spreadsheet imports, work-order routing, quality management, virtual public screens, messaging, notifications, operation triggers, and persisted quality analytics/SPC.

## Tech Stack

- Backend: PHP 8.2+, Laravel 12.
- Auth: Laravel Sanctum for API tokens, Laravel Fortify for web auth and two-factor flows.
- Frontend in same repo: Inertia Laravel 2, Vue 3, TypeScript, Vite 7, Tailwind CSS 4, Reka UI, lucide-vue-next.
- Realtime/broadcast packages: Laravel Reverb is installed; Firebase realtime service exists. Current env defaults use `BROADCAST_CONNECTION=log`.
- Queue/cache/session defaults: database-backed queue, cache, and sessions.
- Spreadsheet import: `phpoffice/phpspreadsheet`.
- AI-related packages/config: `google-gemini-php/client`, `lucianotonet/groq-laravel`, `guzzlehttp/guzzle`; `config/ai_labs.php` defaults to `gpt-4o-mini`; `config/groq.php` defines Groq chat, vision, batch, speech settings.
- Tests: PHPUnit 11 via `php artisan test`.

## Local Project Layout

- `app/Http/Controllers`: API controllers and settings controllers.
- `app/Http/Requests`: request validation classes.
- `app/Models`: Eloquent models for MES domain tables.
- `app/Services`: business logic layer.
- `app/Repositories`: repository layer for several CRUD modules.
- `app/Support`: quality analytics/calendar support classes.
- `app/Jobs`: background quality analytics recalculation job.
- `app/Events`: local realtime event.
- `routes/api.php`: main API surface.
- `routes/web.php`: Inertia dashboard routes.
- `routes/settings.php`: Inertia settings/profile/password/2FA routes.
- `database/migrations`: schema history.
- `database/seeders`: initial domain data and sample/import data.
- `resources/js`: Vue/Inertia frontend.
- `public/images/calibration-masters`: calibration image assets.
- `Work Orders Jan to Nov 2025_Completed`: spreadsheet data used for historical/import flows.
- `docs/quality-analytics-spc.md`: existing quality analytics/SPC notes.

## Coding/Architecture Pattern

The codebase generally follows:

1. Controller validates/normalizes request data.
2. Controller delegates to a service interface or concrete service.
3. Service handles business rules, transactions, persistence, transforms, imports, notifications, and analytics.
4. Repository classes exist for many CRUD domains and are bound in `AppServiceProvider`.
5. API responses generally use `App\Traits\ResponseTrait`, returning success/error/pagination envelopes.

Important service/repository bindings are registered in `app/Providers/AppServiceProvider.php`, including users, auth, customers, batch logs, calibration masters, BOMs, diecuts, work orders, comments, evidences, template routes, machines, operation triggers, packing, supplier change control, virtual screens, playlist items, screen media, and transcripts.

## Authentication And Roles

- API login route: `POST /api/v1/auth/login`.
- Protected routes use `auth:sanctum`.
- Password confirmation: `POST /api/v1/auth/confirm-password`.
- Users have fields such as `firstname`, `lastname`, `middlename`, `position`, `address`, `picture_url`, `user_type`, `finger_print`, `staff_code`, `email`, `password`.
- Fortify two-factor fields are present on users.
- Quality management write operations use a controller-level role check. Allowed values include `qa`, `qa engineer`, `senior qa engineer`, `qa supervisor`, `supervisor`, `manager`, `admin`, `superadmin`.
- Active route monitor requires role/user_type in `supervisor`, `manager`, or `admin`.

## Core Domains

### Users

Model: `User`.

Purpose: Authenticated staff/operators/admins. Users can be assigned to work orders through `UserWorkOrder` and can send/receive messages.

Seeder note: `UpdateUsersSeeder` resets passwords to `admin123` for users with `user_type=admin`, otherwise `test123`. This should be treated as dev/test behavior.

### Customers

Model: `Customer`.

Purpose: Customer master data and options/top-customer summaries based on work orders.

API areas:

- `GET/POST /api/v1/customers`
- `GET /api/v1/customers/top`
- `GET /api/v1/customers/options`
- `GET/PUT/DELETE /api/v1/customers/{id}`

### Work Orders

Model: `WorkOrder`.

Key fillable fields include:

- Customer/linking: `customer_id`, `template_route_id`, `work_order_no`, `batch_number`, `mes_batch_no`, `customer_code`, `customer_name`, `customer_part_number`, `item_code`.
- Materials: `material_1_code` through `material_4_code`.
- Dates/quantities: `production_due_date`, `quantity_to_produce`, `quantity_produced`, `forecast_quantity`, `requested_delivery_date`, `order_date`, `production_start_date`, `production_date_completed`, `production_qty_completed`, `completed_at`.
- Operations/status: `die_cut`, `no_of_colours`, `sales_person_code`, `status`, `priority`, `is_starred`, `sheet`, `is_released`.
- Attachments/meta: `qr_code`, `evidence_images`, `metadata`.

Relations:

- Belongs to `Customer`.
- Belongs to `TemplateRoute`.
- Has many `UserWorkOrder` assignments.
- Has one `PackingChecklist` by `work_order_no`.

Important work-order behavior:

- Supports listing, filtering, pagination, WIP listing, summaries, calendar views, collection reports, import from spreadsheets, batch create/replace, metadata repair, template-route linking, active route monitor, virtualization snapshots, operator assignment syncing, time tracker records, comments, evidences, notifications, and audit logging.
- `work_order_no` is no longer unique; some repair flows require disambiguation by ID when multiple records share the same work order number.
- `metadata` is central. It stores routes, route state, assignments, parameters, time tracker data, completion data, and similar workflow details.

Main API areas:

- `GET/POST /api/v1/work-orders`
- `POST /api/v1/work-orders/batch`
- `POST /api/v1/work-orders/batch/replace`
- `POST /api/v1/work-orders/import`
- `GET /api/v1/work-orders/wip`
- `GET /api/v1/work-orders/summary`
- `GET /api/v1/work-orders/calendar-summary`
- `GET /api/v1/work-orders/calendar-day`
- `GET /api/v1/work-orders/collection-report`
- `GET /api/v1/work-orders/virtualization`
- `GET /api/v1/work-orders/active-routes-monitor`
- `POST /api/v1/work-orders/link-template-routes`
- `PUT /api/v1/work-orders/bulk-update`
- `POST /api/v1/work-orders/{id}/assignments`
- `POST /api/v1/work-orders/{id}/time-tracker`
- `GET/PUT/DELETE /api/v1/work-orders/{id}`

### Template Routes

Model: `TemplateRoute`.

Purpose: Defines production route templates per customer part number, with versioning and metadata derived from spreadsheets.

Key fields:

- `uuid`, `template`, `wod_ref`, `customer_part_number_ref`, `customer_part_no`
- `template_route_version`, `is_active`
- `parent_template_route_id`, `created_from_template_route_id`
- `batch_number`, `sheet`, `user_id`, `metadata`

Important behavior:

- Imports spreadsheet route data using `TemplateRouteImportService`.
- Detects headers in uploaded workbooks, supports regular sheets plus day-month sheet names like `1-Jan`, and month aggregations like `All Jan`.
- Required import columns vary by sheet type. General required columns include customer part number, machine type, and WO journal line number. Day-month sheets require customer part number, machine code, and WO journal line number.
- Normalizes machine/route types to canonical labels such as `DIE-CUT (D)`, `DIE-CUT (L)`, `DIE-CUT`, `FLEXO`, `DIGITAL`, `LP`, `AOI`, `SLITTING`, `INSPECTION`.
- Builds route sequences and route-with-machine summaries.
- Supports version creation and listing versions by customer part number.

API areas:

- `GET/POST /api/v1/template-routes`
- `POST /api/v1/template-routes/import`
- `POST /api/v1/template-routes/batch/replace`
- `GET /api/v1/template-routes/top`
- `GET /api/v1/template-routes/options`
- `GET /api/v1/template-routes/ordered-by-work-orders`
- `GET /api/v1/template-routes/versions`
- `POST /api/v1/template-routes/{id}/version`
- Admin preview/replace: `POST /api/admin/template-routes/preview`, `POST /api/admin/template-routes/replace`

### Machines

Model: `Machine`.

Purpose: Machine master data used by work-order routing, template imports, AOI quality mapping, and seed data.

API areas:

- `GET/POST /api/v1/machines`
- `GET/PUT/DELETE /api/v1/machines/{id}`

### BOM, Diecut, Packing

Models include `Bom`, `Diecut`, `DiecutType`, `DiecutProfile`, `DiecutProfileAlias`, `CustomerPartDiecutProfile`, `DiecutTool`, `DiecutToolUsage`, `Packing`, `PackingChecklist`.

Purpose:

- BOMs and diecuts support batch create/replace and lookup by batch/customer part.
- Diecut intelligence estimates duration and tooling; imports routing and tooling workbooks.
- Packing and packing checklists support packaging data, images/designs, batch import, and lookup by part or batch.

API areas:

- BOM: `/api/v1/boms`, `/api/v1/boms/stats`, `/api/v1/boms/by-batch`, `/api/v1/boms/by-customer-part`, `/api/v1/boms/batch`, `/api/v1/boms/batch/replace`
- Diecut: `/api/v1/diecuts`, `/api/v1/diecuts/by-batch`, `/api/v1/diecuts/batch`, `/api/v1/diecuts/batch/replace`, `/api/v1/diecut-types`
- Diecut intelligence/tooling: `/api/v1/diecuts/estimate`, `/api/v1/diecuts/tooling-summary`, `/api/v1/diecuts/import-routing-workbook`, `/api/v1/diecuts/import-tooling-workbook`, `/api/v1/diecut-profiles`, `/api/v1/diecut-tools`, `/api/v1/diecut-tools/usage`
- Packing: `/api/v1/packings`, `/api/v1/packings/by-part-no`, `/api/v1/packings/by-batch`, `/api/v1/packings/batch`
- Packing checklist: `/api/v1/packing-checklists`

### Quality Management

Models include:

- `Supplier`
- `HolidayCalendar`
- `QualityIssue`
- `QualityFollowUpLot`
- `EightDReport`
- `EightDStep`
- `QualityAttachment`
- `VpdClaim`
- `AoiImportBatch`
- `MeasurementCharacteristicSpec`
- `AoiMeasurementHeader`
- `AoiMeasurementDetail`
- `CalibrationMaster`
- `CalibrationMasterImage`

Purpose:

- Customer/supplier quality issue tracking.
- 8D/SCAR reporting with default D1-D8 steps and working-calendar deadlines.
- VPD claims tied to suppliers/material codes.
- AOI measurement imports from spreadsheets and quality/SPC analytics.
- Calibration master records and images.

Important behavior:

- Quality write routes enforce QA/admin-style roles in `QualityManagementController`.
- Attachments are stored under `public/uploads/quality/{category}`.
- AOI imports store source workbook under `public/uploads/quality/aoi`.
- AOI header duplicate detection checks measurement time, serial counter, lot number, and program name.
- AOI details are created from spreadsheet columns that look like `[number]...`.
- Material code for VPD claims must match known material codes from completed work orders.
- Quality changes dispatch `RecalculateQualityAnalyticsJob`.

API areas:

- Dashboard/filtering: `GET /api/v1/quality/dashboard`, `GET /api/v1/quality/filters`
- Issues: `GET/POST /api/v1/quality/issues`, `PUT/DELETE /api/v1/quality/issues/{id}`
- 8D: `GET/POST /api/v1/quality/8d-reports`, `PUT/DELETE /api/v1/quality/8d-reports/{id}`
- VPD: `GET/POST /api/v1/quality/vpd-claims`, `PUT/DELETE /api/v1/quality/vpd-claims/{id}`
- AOI: `GET /api/v1/quality/aoi-measurements`, `POST /api/v1/quality/aoi-import`, `DELETE /api/v1/quality/aoi-measurements/{id}`
- Analytics: `GET /api/v1/quality/analytics`
- Calibration masters: `GET/POST /api/v1/calibration-masters`, `GET /api/v1/calibration-masters/insights`, `GET/PUT/DELETE /api/v1/calibration-masters/{id}`

### Quality Analytics And SPC

Models:

- `QualityAnalyticsRun`
- `QualityAnalyticsChart`
- `QualityAnalyticsRuleViolation`
- `QualityAnalyticsSourceLink`

Purpose:

- Adds a persisted analytics layer over existing quality data without replacing the source modules.
- `GET /api/v1/quality/analytics` builds filtered quality data, computes chart payloads/SPC metrics, stores analytics runs/charts/violations/source links, and returns the analytics payload.

Implemented analytics include:

- Customer vs supplier issue trends.
- Defect cause Pareto.
- Machine quality ranking.
- Operator quality ranking.
- Calibration compliance.
- VPD claim amount trend.
- Supplier claim Pareto.
- 8D/SCAR aging.
- CAPA follow-up validation lots.
- AOI pass/fail ratio.

SPC/AOI charts include:

- I-MR.
- Histogram.
- Boxplot.
- Run chart.
- Capability metric chart.
- Result distribution.
- X-bar/R when subgroup repeated values exist.

Rule detection includes:

- Point outside control limits.
- Sustained upward/downward trend.
- Shift above/below centerline.
- Repeated near upper/lower limit.
- Sudden process change.
- Abnormal moving range.

### Work-Order Metadata Repair / AI Labs

Controller: `WorkOrderMetadataFixController`.
Service: `WorkOrderMetadataRepairService`.

Purpose:

- Examines and repairs work-order metadata problems that prevent route completion or create inconsistent completion state.

API:

- `POST /api/v1/labs/work-order-metadata/examine`
- `POST /api/v1/labs/work-order-metadata/apply`
- `GET /api/v1/ai-labs/context`

Repair logic:

- Resolves by work order ID or work order number.
- If `work_order_no` has multiple matches, returns selection-required data.
- Normalizes route order, route keys, route codes, route metadata, params, assignments, and state.
- Reconstructs assignment rows from route and time-tracker metadata.
- Can infer completed route status from recent audit logs.
- Sets top-level work-order status/completion fields based on route completion state.

### Operation Triggers

Model: `OperationTrigger`.

Purpose:

- Automation/rules engine for operation events, with publish/disable/simulate/execute/API-tool-preview flows.

Key fields:

- `tenant_id`, `name`, `description`, `status`, `tags`, `rule`, `loop`, `schedule`, `actions`, `flow`, `cooldown`, `debounce`, `version`, `last_fired_at`, `is_active`, `versions`, `audit`, `executions`, `created_by`, `updated_by`.

API:

- `GET/POST /api/v1/operation-triggers`
- `GET/PUT/DELETE /api/v1/operation-triggers/{id}`
- `POST /api/v1/operation-triggers/{id}/publish`
- `POST /api/v1/operation-triggers/{id}/disable`
- `POST /api/v1/operation-triggers/{id}/simulate`
- `POST /api/v1/operation-triggers/{id}/execute`
- Public-ish internal route also exists: `POST /api/v1/operation-triggers/{id}/execute-internal`
- `POST /api/v1/operation-triggers/{id}/api-tool-preview`

### Messaging And Notifications

Models:

- `Message`
- `MessageGroup`
- `WorkOrderNotification`

Purpose:

- User-to-user conversations, message groups, unread counts, mark-read behavior.
- Work-order notifications with unread count and mark read/unread endpoints.
- `FirebaseRealtimeService` can publish message/notification/work-order/trigger updates if Firebase env is configured.

API:

- Messages: `/api/v1/messages`, `/api/v1/messages/threads`, `/api/v1/messages/unread-count`, `/api/v1/messages/conversations/{userId}`, `/api/v1/messages/mark-read`
- Groups: `/api/v1/messages/groups`, `/api/v1/messages/groups/{groupId}`, `/api/v1/messages/groups/{groupId}/mark-read`
- Notifications: `/api/v1/notifications`, `/api/v1/notifications/unread-count`, `/api/v1/notifications/{id}/read`, `/api/v1/notifications/{id}/unread`, `/api/v1/notifications/mark-read`

### Virtual Screens / Public Screen Player

Models:

- `VirtualScreen`
- `PlaylistItem`
- `ScreenMedia`

Purpose:

- Authenticated users create/manage virtual display screens.
- Screens have playlists and uploaded media.
- Public player can be accessed by share token or access code.

API:

- Protected management: `/api/v1/virtual-screens`, `/api/v1/virtual-screens/{id}`, toggle active, regenerate token.
- Playlist: `/api/v1/virtual-screens/{screenId}/playlist-items`, `/api/v1/playlist-items`, reorder, toggle active.
- Media: `/api/v1/virtual-screens/{screenId}/media`, `/api/v1/screen-media/{id}`.
- Public rate-limited routes:
  - `GET /api/v1/public/screens/access-code/{accessCode}`
  - `GET /api/v1/public/screens/{shareToken}`
  - `GET /api/v1/public/screens/{shareToken}/media/{mediaId}`

### Supplier Change Control

Models:

- `SupplierChangeControl`
- `SupplierChangeControlEvent`

Purpose:

- Tracks supplier change-control records, attachments, and step updates.

API:

- `GET/POST /api/v1/supplier-change-controls`
- `GET/PUT/DELETE /api/v1/supplier-change-controls/{id}`
- `POST /api/v1/supplier-change-controls/{id}/step`
- `GET /api/v1/supplier-change-controls/{id}/attachment`

### Audit Logs

Model: `AuditLog`.
Service: `AuditLogService`.

Purpose:

- Records work-order update/change events, time tracker entries, checklist actions, route validation/signoff logs, etc.

API:

- `GET /api/v1/audit-logs`

### Historical Work Orders

Model: `HistoricalWorkOrder`.
Service: `HistoricalWorkOrderService` and `HistoricalWorkOrderImportService`.

Purpose:

- Stores/imports old completed order data from spreadsheets.

API:

- `GET /api/v1/historical-work-orders`
- `GET /api/v1/historical-work-orders/summary`
- `GET /api/v1/historical-work-orders/by-part-number`
- `GET /api/v1/historical-work-orders/filter-options`
- `POST /api/v1/historical-work-orders/import`

### Translation Catalog

Models:

- `FrontendTranslation`
- `FrontendTranslationValue`
- `FrontendTranslationOccurrence`

Services:

- `TranslationService`
- Providers: OpenAI, LibreTranslate, Null provider.
- `LocaleManager`.

Purpose:

- Frontend translation catalog and syncing. Command: `SyncFrontendTranslations`.
- Tests exist for catalog and locale manager.

## API Surface Summary

The application currently exposes 201 API routes according to `php artisan route:list --path=api`.

Public/unprotected or non-Sanctum routes under `/api/v1`:

- `POST /auth/login`
- `POST /transcripts`
- `GET /images/{path}`
- `GET /supplier-change-controls/{id}/attachment`
- `POST /operation-triggers/{id}/execute-internal`
- Public screen player routes under `/public/screens/...` with throttle `60,1`

Most other `/api/v1` routes require `auth:sanctum`.

There is also an `api/admin` route group protected by Sanctum for template route preview/replace.

## Database Tables By Domain

Core/framework:

- `users`, `password_reset_tokens`, `sessions`
- `cache`, `cache_locks`
- `jobs`, `job_batches`, `failed_jobs`
- `personal_access_tokens`

MES master/workflow:

- `customers`
- `work_orders`
- `template_routes`
- `machines`
- `batch_logs`
- `boms`
- `packings`
- `packing_checklists`
- `work_order_comments`
- `work_order_step_completions`
- `work_order_evidences`
- `user_work_orders`
- `work_order_notifications`
- `route_checklist_configurations`
- `work_order_setup_inspection_checklist_records`
- `audit_logs`

Virtual screens:

- `virtual_screens`
- `playlist_items`
- `screen_media`

Diecut:

- `diecut_types`
- `diecuts`
- `diecut_profiles`
- `diecut_profile_aliases`
- `customer_part_diecut_profiles`
- `diecut_tools`
- `diecut_tool_usages`

Messaging:

- `messages`
- `message_groups`
- `message_group_participants`

Automation:

- `operation_triggers`

Supplier/change control:

- `supplier_change_controls`
- `supplier_change_control_events`

Translation:

- `frontend_translations`
- `frontend_translation_values`
- `frontend_translation_occurrences`

Historical and quality:

- `historical_work_orders`
- `calibration_masters`
- `calibration_master_images`
- `suppliers`
- `holiday_calendars`
- `quality_issues`
- `quality_follow_up_lots`
- `eight_d_reports`
- `eight_d_steps`
- `quality_attachments`
- `vpd_claims`
- `aoi_import_batches`
- `measurement_characteristic_specs`
- `aoi_measurement_headers`
- `aoi_measurement_details`
- `quality_analytics_runs`
- `quality_analytics_charts`
- `quality_analytics_rule_violations`
- `quality_analytics_source_links`

## Seeders

`DatabaseSeeder` calls:

- `UserSeeder`
- `CustomerSeeder`
- `TemplateRouteSeeder`
- `WorkOrderSeeder`
- `MachineSeeder`
- `MachineImageSeeder`
- `CalibrationMasterSeeder`
- `DiecutTypeSeeder`
- `DiecutIntegrationSeeder`
- `RouteChecklistConfigurationSeeder`

Other available seeders include:

- `UpdateUsersSeeder`
- `WorkOrderSampleSeeder`
- `MachineTypeUpdateSeeder`
- `DiecutIntegrationSeeder`
- `CustomerSeeder`
- `CalibrationMasterSeeder`

Seeder data exists under `database/seeders/data`, including work-order seed data and machine JSON files.

## Environment Notes

Do not paste real secret values into prompts. The repo contains `.env`, `.env.dev`, and `.env.prod`; document keys only.

Important env keys:

- App: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, locale keys.
- DB: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Session/cache/queue: `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`.
- Broadcast/realtime: `BROADCAST_CONNECTION`, Reverb config keys if enabled, Firebase keys `FIREBASE_DATABASE_URL`, `FIREBASE_SERVICE_ACCOUNT_PATH`.
- Mail/Mailtrap: `MAIL_*`, `MAILTRAP_*`.
- AWS/filesystem: `AWS_*`, `FILESYSTEM_DISK`.
- AI/Groq/OpenAI-style: `GROQ_API_KEY`, `GROQ_MODEL`, `GROQ_*`, and any OpenAI/translation provider keys if configured.
- Frontend: `VITE_APP_NAME`.

Current local-style defaults observed:

- MySQL is used.
- App timezone is `Asia/Manila`.
- Queue/cache/session are database-backed.
- Mail defaults to log.
- Broadcast defaults to log.

## Commands

Install/setup:

```bash
composer setup
```

Development:

```bash
composer dev
```

SSR development:

```bash
composer dev:ssr
```

Frontend only:

```bash
npm run dev
```

Build:

```bash
npm run build
npm run build:ssr
```

Tests:

```bash
composer test
php artisan test
```

Formatting/linting:

```bash
npm run format
npm run format:check
npm run lint
```

Route inspection:

```bash
php artisan route:list --path=api
```

## Existing Tests

Feature tests:

- `DashboardTest`
- `QualityAnalyticsControllerTest`
- `TemplateRouteImportEndpointsTest`
- `WorkOrderMetadataFixExamineTest`
- `WorkOrderMetadataUpdateTest`
- Auth/Fortify tests for authentication, email verification, password reset/confirmation, registration, two-factor, verification notification.
- Settings tests for password, profile, two-factor.

Unit tests:

- `LocaleManagerTest`
- `QualityAnalyticsNativeEngineTest`
- `TemplateRouteImportServiceTest`
- `TranslationCatalogTest`

## Current Caveats / Things Claude Should Notice

- Some controllers have commented-out try/catch blocks around import/batch flows. Errors may surface directly in those paths.
- `work_order_no` is not unique anymore; routes that use work-order number may need disambiguation.
- Work-order `metadata` is critical and loosely structured. Changes should preserve existing nested route/state/assignment/time-tracker keys unless deliberately migrating them.
- The repo contains real `.env` files and likely secret material. Do not echo secret values in generated documentation or prompts.
- Some text in files appears mojibake-encoded, for example the arrow in `UpdateUsersSeeder` showed as `â†’` when read through the shell.
- `AppServiceProvider` imports and binds interfaces for many modules, but not every service in `app/Services` is interface-bound.
- Frontend code is present in this backend repo; backend API changes may be consumed by `resources/js` pages/components.
- Public virtual-screen routes are rate-limited but not authenticated.
- Quality analytics is additive and persisted; source of truth remains the base quality tables.

## Best Working Strategy For Future AI Changes

1. Start with `routes/api.php`, the relevant controller, service, request class, model, and migration.
2. Preserve response envelope conventions from `ResponseTrait`.
3. Prefer existing service/repository patterns over new abstractions.
4. Be careful with work-order metadata shape; add tests before changing route completion/assignment behavior.
5. For imports, inspect the corresponding service because much of the logic is in spreadsheet mapping/normalization.
6. For quality changes, consider whether `RecalculateQualityAnalyticsJob` should be dispatched.
7. For user-visible API changes, check `resources/js` consumers.
8. Run `php artisan test` or focused tests before finalizing.

