<?php

namespace App\Services;

use App\Models\AoiImportBatch;
use App\Models\AoiMeasurementDetail;
use App\Models\AoiMeasurementHeader;
use App\Models\CalibrationMaster;
use App\Models\Customer;
use App\Models\EightDReport;
use App\Models\EightDStep;
use App\Models\Machine;
use App\Models\MeasurementCharacteristicSpec;
use App\Models\QualityAttachment;
use App\Models\QualityFollowUpLot;
use App\Models\QualityIssue;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VpdClaim;
use App\Models\WorkOrder;
use App\Support\CalibrationSchedule;
use App\Support\QualityWorkingCalendar;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class QualityManagementService
{
    public function dashboard(array $filters = []): array
    {
        $issues = $this->applyIssueFilters(QualityIssue::query(), $filters)->get();
        $aoi = $this->applyAoiFilters(AoiMeasurementHeader::query(), $filters)->with('details')->get();
        $today = Carbon::today();

        return [
            'summary' => [
                'total_cases' => $issues->count(),
                'minor_cases' => $issues->where('severity', 'Minor')->count(),
                'major_cases' => $issues->filter(fn (QualityIssue $issue) => strtolower((string) $issue->severity) !== 'minor')->count(),
                'closed_cases' => $issues->filter(fn (QualityIssue $issue) => strtolower((string) $issue->status) === 'closed')->count(),
                'open_cases' => $issues->filter(fn (QualityIssue $issue) => strtolower((string) $issue->status) === 'open')->count(),
                'in_progress_cases' => $issues->filter(fn (QualityIssue $issue) => strtolower((string) $issue->status) === 'in-progress')->count(),
                'on_hold_cases' => $issues->filter(fn (QualityIssue $issue) => strtolower((string) $issue->status) === 'on-hold')->count(),
                'average_turnaround_days' => round($issues->filter(fn (QualityIssue $issue) => $issue->actual_tat_days !== null)->avg('actual_tat_days') ?? 0, 1),
                'overdue_cases' => $issues->filter(fn (QualityIssue $issue) => $issue->target_closure_date && ! $issue->closure_date && $issue->target_closure_date->lt($today))->count(),
                'calibration_due_soon' => CalibrationMaster::query()->where('is_active', true)->whereBetween('next_calibration_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])->count(),
                'calibration_overdue' => CalibrationMaster::query()->where('is_active', true)->whereDate('next_calibration_date', '<', $today)->count(),
            ],
            'issue_type_trends' => [
                'customer' => $this->trendSeries($issues->where('issue_type', 'customer')),
                'supplier' => $this->trendSeries($issues->where('issue_type', 'supplier')),
            ],
            'defect_pareto' => $this->paretoSeries($issues),
            'rework_rejection_trends' => $this->rejectionTrendSeries($issues),
            'good_vs_bad' => [
                'good_qty' => $aoi->where('result_status', 'OK')->count(),
                'bad_qty' => $aoi->where('result_status', 'NG')->count(),
            ],
            'machine_quality_performance' => $this->groupCountSeries($issues, fn (QualityIssue $issue) => data_get($issue, 'metadata.machine') ?: 'Unspecified', 'machine'),
            'operator_quality_performance' => $this->groupCountSeries($issues, fn (QualityIssue $issue) => $issue->pic ?: 'Unspecified', 'operator'),
            'aoi_measurement_health' => $this->aoiHealthSummary($aoi),
            'drilldown_filters' => $this->filterOptions(),
        ];
    }

    public function listIssues(array $filters = [], int $limit = 25, int $page = 1): array
    {
        $paginator = $this->applyIssueFilters(QualityIssue::query()->with(['followUpLots', 'attachments', 'eightDReports']), $filters)
            ->orderByDesc('date_issue')
            ->orderByDesc('updated_at')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginateWithTransform($paginator, fn (QualityIssue $issue) => $this->transformIssue($issue));
    }

    public function saveIssue(array $data, ?int $id = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $id, $actor): array {
            $issue = $id ? QualityIssue::query()->findOrFail($id) : new QualityIssue();
            $issue->fill($this->normalizeIssuePayload($data));
            $issue->type_label = trim(($issue->severity ?: 'Issue') . '-' . ($issue->status ?: 'Open'));
            $issue->save();

            if (isset($data['follow_up_lots']) && is_array($data['follow_up_lots'])) {
                $issue->followUpLots()->delete();
                foreach ($data['follow_up_lots'] as $index => $lot) {
                    $issue->followUpLots()->create([
                        'sequence_no' => $index + 1,
                        'label' => $lot['label'] ?? ('Validation ' . ($index + 1)),
                        'work_order_no' => $lot['work_order_no'] ?? null,
                        'lot_number' => $lot['lot_number'] ?? null,
                        'result_status' => $lot['result_status'] ?? null,
                        'remarks' => $lot['remarks'] ?? null,
                    ]);
                }
            }

            foreach (($data['attachment_files'] ?? []) as $file) {
                if ($file instanceof UploadedFile) {
                    $this->storeAttachment($issue, $file, 'issue', $actor);
                }
            }

            return $this->transformIssue($issue->load(['followUpLots', 'attachments', 'eightDReports']));
        });
    }

    public function deleteIssue(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $issue = QualityIssue::query()->with(['attachments', 'followUpLots'])->findOrFail($id);
            $this->purgeAttachments($issue->attachments);
            $issue->delete();
        });
    }

    public function listEightDReports(array $filters = [], int $limit = 25, int $page = 1): array
    {
        $paginator = EightDReport::query()
            ->with(['steps', 'attachments', 'qualityIssue'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['tracking_number'] ?? null, fn ($q, $v) => $q->where('tracking_number', 'like', '%' . $v . '%'))
            ->orderByDesc('date_issue')
            ->orderByDesc('updated_at')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginateWithTransform($paginator, fn (EightDReport $report) => $this->transformEightDReport($report));
    }

    public function saveEightDReport(array $data, ?int $id = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $id, $actor): array {
            $issueDate = CalibrationSchedule::parseDate($data['date_issue'] ?? null);
            $deadlines = QualityWorkingCalendar::buildEightDDeadlines($issueDate);
            $report = $id ? EightDReport::query()->findOrFail($id) : new EightDReport();
            if (! $report->exists) {
                $report->report_number = $data['report_number'] ?? $this->nextReportNumber();
            }

            $report->fill([
                'quality_issue_id' => $data['quality_issue_id'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'date_issue' => $issueDate?->toDateString(),
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                'assigned_to_name' => $this->resolveUserName($data['assigned_to_user_id'] ?? null, $data['assigned_to_name'] ?? null),
                'severity' => $data['severity'] ?? null,
                'status' => $data['status'] ?? 'Open',
                'ack_due_at' => $deadlines['ack_due_at'] ?? null,
                'd3_due_at' => $deadlines['d3_due_at'] ?? null,
                'd4_due_at' => $deadlines['d4_due_at'] ?? null,
                'd5_due_at' => $deadlines['d5_due_at'] ?? null,
                'd8_due_at' => $deadlines['d8_due_at'] ?? null,
                'closure_due_at' => $deadlines['closure_due_at'] ?? null,
                'closed_at' => strtolower((string) ($data['status'] ?? '')) === 'closed' ? Carbon::now() : null,
                'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            ]);
            $report->save();

            if (isset($data['steps']) && is_array($data['steps'])) {
                $report->steps()->delete();
                foreach ($data['steps'] as $step) {
                    $report->steps()->create([
                        'step_code' => $step['step_code'],
                        'title' => $step['title'] ?? $step['step_code'],
                        'owner_user_id' => $step['owner_user_id'] ?? null,
                        'owner_name' => $this->resolveUserName($step['owner_user_id'] ?? null, $step['owner_name'] ?? null),
                        'is_completed' => (bool) ($step['is_completed'] ?? false),
                        'completed_by_user_id' => $step['completed_by_user_id'] ?? null,
                        'completed_at' => CalibrationSchedule::parseDate($step['completed_at'] ?? null)?->toDateString(),
                        'approval_status' => $step['approval_status'] ?? 'Pending',
                        'content' => $step['content'] ?? null,
                        'remarks' => $step['remarks'] ?? null,
                    ]);
                }
            } elseif (! $report->steps()->exists()) {
                foreach ($this->defaultEightDSteps() as $step) {
                    $report->steps()->create($step);
                }
            }

            foreach (($data['attachment_files'] ?? []) as $file) {
                if ($file instanceof UploadedFile) {
                    $this->storeAttachment($report, $file, '8d', $actor);
                }
            }

            $report = $report->load(['steps', 'attachments', 'qualityIssue']);
            if ($report->steps->firstWhere('step_code', 'D8')?->is_completed) {
                $report->status = 'Closed';
                $report->closed_at = $report->closed_at ?: Carbon::now();
                $report->save();
            }

            return $this->transformEightDReport($report->fresh(['steps', 'attachments', 'qualityIssue']));
        });
    }

    public function deleteEightDReport(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $report = EightDReport::query()->with(['attachments', 'steps'])->findOrFail($id);
            $this->purgeAttachments($report->attachments);
            $report->delete();
        });
    }

    public function listVpdClaims(array $filters = [], int $limit = 25, int $page = 1): array
    {
        $paginator = VpdClaim::query()
            ->with(['supplier', 'qualityIssue', 'attachments'])
            ->when($filters['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_name', 'like', '%' . $v . '%'))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['material_code'] ?? null, fn ($q, $v) => $q->where('material_code', 'like', '%' . $v . '%'))
            ->orderByDesc('claim_date')
            ->orderByDesc('updated_at')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginateWithTransform($paginator, fn (VpdClaim $claim) => $this->transformVpdClaim($claim));
    }

    public function saveVpdClaim(array $data, ?int $id = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $id, $actor): array {
            $claim = $id ? VpdClaim::query()->findOrFail($id) : new VpdClaim();
            $materialCode = $this->resolveKnownMaterialCode($data['material_code'] ?? null);
            $claim->fill([
                'vpd_number' => $data['vpd_number'] ?? ($claim->vpd_number ?: $this->nextVpdNumber()),
                'claim_date' => CalibrationSchedule::parseDate($data['claim_date'] ?? null)?->toDateString(),
                'supplier_id' => $this->resolveSupplierId($data),
                'vendor_name' => $data['vendor_name'] ?? null,
                'material_code' => $materialCode,
                'description' => $data['description'] ?? null,
                'defect_type' => $data['defect_type'] ?? null,
                'sqm' => $data['sqm'] ?? null,
                'unit_price' => $this->decimalOrNull($data['unit_price'] ?? null),
                'amount' => $this->decimalOrNull($data['amount'] ?? null),
                'currency' => $data['currency'] ?? null,
                'exchange_rate' => $this->decimalOrNull($data['exchange_rate'] ?? null),
                'total_sgd' => $this->decimalOrNull($data['total_sgd'] ?? null),
                'car_completion_date' => CalibrationSchedule::parseDate($data['car_completion_date'] ?? null)?->toDateString(),
                'remarks' => $data['remarks'] ?? null,
                'additional_reference' => $data['additional_reference'] ?? null,
                'status' => $data['status'] ?? 'Open',
                'notes' => $data['notes'] ?? null,
                'quality_issue_id' => $data['quality_issue_id'] ?? null,
                'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            ]);
            $claim->save();

            foreach (($data['attachment_files'] ?? []) as $file) {
                if ($file instanceof UploadedFile) {
                    $this->storeAttachment($claim, $file, 'vpd', $actor);
                }
            }

            return $this->transformVpdClaim($claim->load(['supplier', 'qualityIssue', 'attachments']));
        });
    }

    public function deleteVpdClaim(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $claim = VpdClaim::query()->with('attachments')->findOrFail($id);
            $this->purgeAttachments($claim->attachments);
            $claim->delete();
        });
    }

    public function listAoiMeasurements(array $filters = [], int $limit = 50, int $page = 1): array
    {
        $paginator = $this->applyAoiFilters(AoiMeasurementHeader::query()->with(['details', 'attachments']), $filters)
            ->orderByDesc('measurement_time')
            ->orderByDesc('updated_at')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginateWithTransform($paginator, fn (AoiMeasurementHeader $header) => $this->transformAoiMeasurement($header));
    }

    public function importAoiWorkbook(UploadedFile $file, ?User $actor = null): array
    {
        $targetDir = public_path('uploads/quality/aoi');
        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true, true);
        }

        $storedName = 'aoi_' . time() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $file->getClientOriginalName());
        $file->move($targetDir, $storedName);
        $batch = AoiImportBatch::query()->create([
            'source_type' => 'upload',
            'source_file_name' => $file->getClientOriginalName(),
            'source_file_path' => '/uploads/quality/aoi/' . $storedName,
            'import_status' => 'processing',
            'started_by_user_id' => $actor?->id,
        ]);

        $stats = $this->ingestAoiWorkbook($targetDir . DIRECTORY_SEPARATOR . $storedName, $batch);
        $batch->update([
            'import_status' => 'completed',
            'row_count' => $stats['row_count'],
            'imported_count' => $stats['imported_count'],
            'duplicate_count' => $stats['duplicate_count'],
            'imported_at' => Carbon::now(),
            'mapping' => $stats['mapping'],
        ]);

        return ['batch_id' => $batch->id] + Arr::except($stats, ['mapping']);
    }

    public function deleteAoiMeasurement(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $header = AoiMeasurementHeader::query()->with(['attachments', 'details'])->findOrFail($id);
            $this->purgeAttachments($header->attachments);
            $header->delete();
        });
    }

    public function filterOptions(): array
    {
        $completedWorkOrders = WorkOrder::query()
            ->select([
                'id',
                'work_order_no',
                'customer_name',
                'customer_part_number',
                'batch_number',
                'production_date_completed',
                'status',
                'item_code',
                'material_1_code',
                'material_2_code',
                'material_3_code',
                'material_4_code',
            ])
            ->where(function ($query) {
                $query->whereNotNull('production_date_completed')
                    ->orWhereNotNull('completed_at')
                    ->orWhereRaw("LOWER(TRIM(COALESCE(status, ''))) = ?", ['completed']);
            })
            ->orderByDesc('production_date_completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        $materialCodes = $completedWorkOrders
            ->flatMap(function (WorkOrder $order): array {
                return array_values(array_filter([
                    $order->item_code,
                    $order->material_1_code,
                    $order->material_2_code,
                    $order->material_3_code,
                    $order->material_4_code,
                ]));
            })
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return [
            'customers' => Customer::query()->orderBy('customer_name')->pluck('customer_name')->filter()->values(),
            'suppliers' => Supplier::query()->orderBy('supplier_name')->pluck('supplier_name')->filter()->values(),
            'material_codes' => $materialCodes,
            'work_orders' => $completedWorkOrders->pluck('work_order_no')->filter()->unique()->values(),
            'completed_work_orders' => $completedWorkOrders->map(fn (WorkOrder $order) => [
                'id' => $order->id,
                'work_order_no' => $order->work_order_no,
                'customer_name' => $order->customer_name,
                'part_number' => $order->customer_part_number,
                'batch_number' => $order->batch_number,
                'item_code' => $order->item_code,
                'material_codes' => array_values(array_filter([
                    $order->item_code,
                    $order->material_1_code,
                    $order->material_2_code,
                    $order->material_3_code,
                    $order->material_4_code,
                ])),
                'production_date_completed' => $order->production_date_completed?->toDateString(),
                'status' => $order->status,
                'search_label' => trim(implode(' · ', array_filter([
                    $order->work_order_no,
                    $order->customer_name,
                    $order->customer_part_number,
                    $order->batch_number ? 'Lot ' . $order->batch_number : null,
                ]))),
            ])->values(),
            'machines' => Machine::query()->orderBy('machine_name')->pluck('machine_name')->filter()->values(),
            'operators' => User::query()->orderBy('firstname')->get()->map(fn (User $user) => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')))->filter()->values(),
            'severities' => QualityIssue::query()->select('severity')->distinct()->orderBy('severity')->pluck('severity')->filter()->values(),
            'statuses' => QualityIssue::query()->select('status')->distinct()->orderBy('status')->pluck('status')->filter()->values(),
        ];
    }

    protected function normalizeIssuePayload(array $data): array
    {
        $issueDate = CalibrationSchedule::parseDate($data['date_issue'] ?? null);
        $closureDate = CalibrationSchedule::parseDate($data['closure_date'] ?? null);
        $workOrder = null;
        $resolvedWorkOrderId = $this->resolveWorkOrderId($data);
        if ($resolvedWorkOrderId) {
            $workOrder = WorkOrder::query()->find($resolvedWorkOrderId);
        }

        return [
            'issue_type' => strtolower((string) ($data['issue_type'] ?? 'customer')),
            'serial_no' => $data['serial_no'] ?? null,
            'date_issue' => $issueDate?->toDateString(),
            'month_label' => trim((string) ($data['month_label'] ?? ($issueDate ? $issueDate->format('M-y') : ''))),
            'tracking_number' => $data['tracking_number'] ?: $this->nextIssueTrackingNumber(strtolower((string) ($data['issue_type'] ?? 'customer'))),
            'external_tracking_number' => $data['external_tracking_number'] ?? null,
            'customer_id' => $workOrder?->customer_id ?: $this->resolveCustomerId($data),
            'supplier_id' => $this->resolveSupplierId($data),
            'customer_name' => $workOrder?->customer_name ?: ($data['customer_name'] ?? null),
            'supplier_name' => $data['supplier_name'] ?? null,
            'severity' => $data['severity'] ?? null,
            'work_order_id' => $resolvedWorkOrderId,
            'work_order_no' => $workOrder?->work_order_no ?: ($data['work_order_no'] ?? null),
            'part_number' => $workOrder?->customer_part_number ?: ($data['part_number'] ?? null),
            'part_name' => $data['part_name'] ?? null,
            'material_code' => $data['material_code'] ?? null,
            'material_name' => $data['material_name'] ?? null,
            'lot_number' => $data['lot_number'] ?? ($workOrder?->batch_number ?: null),
            'problem_statement' => $data['problem_statement'] ?? null,
            'reject_rate' => $data['reject_rate'] ?? null,
            'pic' => $data['pic'] ?? null,
            'status' => $data['status'] ?? 'Open',
            'closure_date' => $closureDate?->toDateString(),
            'target_acknowledgement_date' => CalibrationSchedule::parseDate($data['target_acknowledgement_date'] ?? null)?->toDateString(),
            'actual_acknowledgement_date' => CalibrationSchedule::parseDate($data['actual_acknowledgement_date'] ?? null)?->toDateString(),
            'kpi_days' => $data['kpi_days'] ?? null,
            'target_closure_date' => CalibrationSchedule::parseDate($data['target_closure_date'] ?? null)?->toDateString(),
            'actual_tat_days' => $issueDate && $closureDate ? $issueDate->diffInDays($closureDate) : null,
            'root_cause' => $data['root_cause'] ?? null,
            'immediate_action' => $data['immediate_action'] ?? null,
            'corrective_action' => $data['corrective_action'] ?? null,
            'preventive_action' => $data['preventive_action'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'comment' => $data['comment'] ?? null,
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        ];
    }

    protected function applyIssueFilters($query, array $filters)
    {
        return $query
            ->when($filters['issue_type'] ?? null, fn ($q, $v) => $q->where('issue_type', strtolower((string) $v)))
            ->when($filters['tracking_number'] ?? null, fn ($q, $v) => $q->where('tracking_number', 'like', '%' . $v . '%'))
            ->when($filters['customer'] ?? null, fn ($q, $v) => $q->where('customer_name', 'like', '%' . $v . '%'))
            ->when($filters['supplier'] ?? null, fn ($q, $v) => $q->where('supplier_name', 'like', '%' . $v . '%'))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['work_order_no'] ?? null, fn ($q, $v) => $q->where('work_order_no', 'like', '%' . $v . '%'))
            ->when($filters['part_number'] ?? null, fn ($q, $v) => $q->where('part_number', 'like', '%' . $v . '%'))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('date_issue', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('date_issue', '<=', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $q->where(function ($inner) use ($v) {
                    $like = '%' . $v . '%';
                    $inner->where('tracking_number', 'like', $like)
                        ->orWhere('problem_statement', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhere('status', 'like', $like);
                });
            });
    }

    protected function applyAoiFilters($query, array $filters)
    {
        return $query
            ->when($filters['customer'] ?? null, fn ($q, $v) => $q->where('customer_name', 'like', '%' . $v . '%'))
            ->when($filters['work_order_no'] ?? null, fn ($q, $v) => $q->where('work_order_no', 'like', '%' . $v . '%'))
            ->when($filters['part_number'] ?? null, fn ($q, $v) => $q->where('part_number', 'like', '%' . $v . '%'))
            ->when($filters['machine'] ?? null, fn ($q, $v) => $q->where('machine_number', 'like', '%' . $v . '%'))
            ->when($filters['operator'] ?? null, fn ($q, $v) => $q->where('operator_name', 'like', '%' . $v . '%'))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('measurement_time', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('measurement_time', '<=', $v));
    }

    protected function transformIssue(QualityIssue $issue): array
    {
        $today = Carbon::today();

        return [
            'id' => $issue->id,
            'issue_type' => $issue->issue_type,
            'serial_no' => $issue->serial_no,
            'date_issue' => $issue->date_issue?->toDateString(),
            'month_label' => $issue->month_label,
            'tracking_number' => $issue->tracking_number,
            'external_tracking_number' => $issue->external_tracking_number,
            'customer_name' => $issue->customer_name,
            'supplier_name' => $issue->supplier_name,
            'severity' => $issue->severity,
            'work_order_no' => $issue->work_order_no,
            'part_number' => $issue->part_number,
            'part_name' => $issue->part_name,
            'material_code' => $issue->material_code,
            'material_name' => $issue->material_name,
            'lot_number' => $issue->lot_number,
            'problem_statement' => $issue->problem_statement,
            'reject_rate' => $issue->reject_rate,
            'pic' => $issue->pic,
            'status' => $issue->status,
            'closure_date' => $issue->closure_date?->toDateString(),
            'remarks' => $issue->remarks,
            'comment' => $issue->comment,
            'target_acknowledgement_date' => $issue->target_acknowledgement_date?->toDateString(),
            'actual_acknowledgement_date' => $issue->actual_acknowledgement_date?->toDateString(),
            'kpi_days' => $issue->kpi_days,
            'root_cause' => $issue->root_cause,
            'immediate_action' => $issue->immediate_action,
            'corrective_action' => $issue->corrective_action,
            'preventive_action' => $issue->preventive_action,
            'target_closure_date' => $issue->target_closure_date?->toDateString(),
            'actual_tat_days' => $issue->actual_tat_days,
            'overdue' => $issue->target_closure_date && ! $issue->closure_date && $issue->target_closure_date->lt($today),
            'type_label' => $issue->type_label,
            'attachments' => $issue->attachments->map(fn (QualityAttachment $attachment) => $this->transformAttachment($attachment))->values()->all(),
            'follow_up_lots' => $issue->followUpLots->map(fn (QualityFollowUpLot $lot) => [
                'id' => $lot->id,
                'sequence_no' => $lot->sequence_no,
                'label' => $lot->label,
                'work_order_no' => $lot->work_order_no,
                'lot_number' => $lot->lot_number,
                'result_status' => $lot->result_status,
                'remarks' => $lot->remarks,
            ])->values()->all(),
            'linked_eight_d_reports' => $issue->eightDReports->map(fn (EightDReport $report) => [
                'id' => $report->id,
                'report_number' => $report->report_number,
                'status' => $report->status,
            ])->values()->all(),
            'metadata' => $issue->metadata,
        ];
    }

    protected function transformEightDReport(EightDReport $report): array
    {
        return [
            'id' => $report->id,
            'report_number' => $report->report_number,
            'tracking_number' => $report->tracking_number,
            'date_issue' => $report->date_issue?->toDateString(),
            'assigned_to_user_id' => $report->assigned_to_user_id,
            'assigned_to_name' => $report->assigned_to_name,
            'severity' => $report->severity,
            'status' => $report->status,
            'ack_due_at' => $report->ack_due_at?->toIso8601String(),
            'd3_due_at' => $report->d3_due_at?->toIso8601String(),
            'd4_due_at' => $report->d4_due_at?->toIso8601String(),
            'd5_due_at' => $report->d5_due_at?->toIso8601String(),
            'd8_due_at' => $report->d8_due_at?->toIso8601String(),
            'closure_due_at' => $report->closure_due_at?->toIso8601String(),
            'closed_at' => $report->closed_at?->toIso8601String(),
            'steps' => $report->steps->map(fn (EightDStep $step) => [
                'id' => $step->id,
                'step_code' => $step->step_code,
                'title' => $step->title,
                'owner_user_id' => $step->owner_user_id,
                'owner_name' => $step->owner_name,
                'is_completed' => $step->is_completed,
                'completed_at' => $step->completed_at?->toIso8601String(),
                'approval_status' => $step->approval_status,
                'content' => $step->content,
                'remarks' => $step->remarks,
            ])->values()->all(),
            'attachments' => $report->attachments->map(fn (QualityAttachment $attachment) => $this->transformAttachment($attachment))->values()->all(),
            'metadata' => $report->metadata,
        ];
    }

    protected function transformVpdClaim(VpdClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'vpd_number' => $claim->vpd_number,
            'claim_date' => $claim->claim_date?->toDateString(),
            'vendor_name' => $claim->vendor_name,
            'material_code' => $claim->material_code,
            'description' => $claim->description,
            'defect_type' => $claim->defect_type,
            'sqm' => $claim->sqm,
            'unit_price' => $claim->unit_price,
            'amount' => $claim->amount,
            'currency' => $claim->currency,
            'exchange_rate' => $claim->exchange_rate,
            'total_sgd' => $claim->total_sgd,
            'car_completion_date' => $claim->car_completion_date?->toDateString(),
            'remarks' => $claim->remarks,
            'additional_reference' => $claim->additional_reference,
            'status' => $claim->status,
            'notes' => $claim->notes,
            'attachments' => $claim->attachments->map(fn (QualityAttachment $attachment) => $this->transformAttachment($attachment))->values()->all(),
            'metadata' => $claim->metadata,
        ];
    }

    protected function transformAttachment(QualityAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'category' => $attachment->attachment_category,
            'file_name' => $attachment->file_name,
            'file_path' => $attachment->file_path,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
        ];
    }

    protected function transformAoiMeasurement(AoiMeasurementHeader $header): array
    {
        $stats = $this->aoiMeasurementStats($header->details);

        return [
            'id' => $header->id,
            'measurement_time' => $header->measurement_time?->toIso8601String(),
            'result_status' => $header->result_status,
            'lot_number' => $header->lot_number,
            'serial_counter' => $header->serial_counter,
            'operator_name' => $header->operator_name,
            'roll_id' => $header->roll_id,
            'machine_number' => $header->machine_number,
            'computer_name' => $header->computer_name,
            'program_name' => $header->program_name,
            'work_order_no' => $header->work_order_no,
            'customer_name' => $header->customer_name,
            'part_number' => $header->part_number,
            'batch_number' => $header->batch_number,
            'error_code' => $header->error_code,
            'stop_reason' => $header->stop_reason,
            'is_reinspection' => $header->is_reinspection,
            'stats' => $stats,
            'details' => $header->details->map(fn (AoiMeasurementDetail $detail) => [
                'id' => $detail->id,
                'characteristic_code' => $detail->characteristic_code,
                'characteristic_name' => $detail->characteristic_name,
                'numeric_value' => $detail->numeric_value,
                'raw_value' => $detail->raw_value,
                'units' => $detail->units,
                'nominal_value' => $detail->nominal_value,
                'lower_spec_limit' => $detail->lower_spec_limit,
                'upper_spec_limit' => $detail->upper_spec_limit,
                'is_out_of_spec' => $detail->is_out_of_spec,
            ])->values()->all(),
            'attachments' => $header->attachments->map(fn (QualityAttachment $attachment) => $this->transformAttachment($attachment))->values()->all(),
        ];
    }

    protected function paginateWithTransform(LengthAwarePaginator $paginator, callable $transformer): array
    {
        return [
            'data' => collect($paginator->items())->map($transformer)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function trendSeries(Collection $issues): array
    {
        return $issues
            ->groupBy(fn (QualityIssue $issue) => $issue->date_issue?->format('Y-m') ?: 'Unknown')
            ->map(fn (Collection $group, string $period) => ['period' => $period, 'count' => $group->count()])
            ->values()
            ->sortBy('period')
            ->values()
            ->all();
    }

    protected function paretoSeries(Collection $issues): array
    {
        return $issues
            ->groupBy(fn (QualityIssue $issue) => trim((string) ($issue->problem_statement ?: 'Unspecified')))
            ->map(fn (Collection $group, string $label) => ['label' => $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    protected function rejectionTrendSeries(Collection $issues): array
    {
        return $issues
            ->groupBy(fn (QualityIssue $issue) => $issue->date_issue?->format('Y-m') ?: 'Unknown')
            ->map(function (Collection $group, string $period): array {
                return [
                    'period' => $period,
                    'rejects' => $group->count(),
                    'rework' => $group->filter(fn (QualityIssue $issue) => str_contains(strtolower((string) $issue->comment), 'rework'))->count(),
                ];
            })
            ->sortBy('period')
            ->values()
            ->all();
    }

    protected function groupCountSeries(Collection $items, callable $resolver, string $labelKey = 'label'): array
    {
        return $items
            ->groupBy(function ($item) use ($resolver) {
                $value = $resolver($item);
                return trim((string) ($value ?: 'Unspecified'));
            })
            ->map(fn (Collection $group, string $label) => [$labelKey => $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    protected function aoiHealthSummary(Collection $headers): array
    {
        $detailRows = $headers->flatMap(fn (AoiMeasurementHeader $header) => $header->details);
        $outOfSpec = $detailRows->where('is_out_of_spec', true)->count();
        $ok = $headers->where('result_status', 'OK')->count();
        $ng = $headers->where('result_status', 'NG')->count();
        $total = $headers->count();

        return [
            'total_measurements' => $total,
            'ok_count' => $ok,
            'ng_count' => $ng,
            'ng_ratio' => $total > 0 ? round(($ng / $total) * 100, 2) : 0,
            'out_of_spec_points' => $outOfSpec,
            'characteristics_monitored' => $detailRows->pluck('characteristic_code')->filter()->unique()->count(),
        ];
    }

    protected function aoiMeasurementStats(Collection $details): array
    {
        $values = $details
            ->pluck('numeric_value')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values();

        if ($values->isEmpty()) {
            return [
                'count' => 0,
                'min' => null,
                'max' => null,
                'mean' => null,
                'range' => null,
                'sigma' => null,
                'out_of_spec_count' => $details->where('is_out_of_spec', true)->count(),
            ];
        }

        $count = $values->count();
        $mean = $values->avg();
        $variance = $count > 1
            ? $values->reduce(fn ($carry, $value) => $carry + (($value - $mean) ** 2), 0.0) / ($count - 1)
            : 0.0;
        $sigma = sqrt($variance);

        return [
            'count' => $count,
            'min' => round($values->min(), 6),
            'max' => round($values->max(), 6),
            'mean' => round($mean, 6),
            'range' => round($values->max() - $values->min(), 6),
            'sigma' => round($sigma, 6),
            'out_of_spec_count' => $details->where('is_out_of_spec', true)->count(),
        ];
    }

    protected function storeAttachment($attachable, UploadedFile $file, string $category, ?User $actor = null): QualityAttachment
    {
        $directory = public_path('uploads/quality/' . $category);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $storedName = strtolower($category) . '_' . time() . '_' . uniqid() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $file->getClientOriginalName());
        $file->move($directory, $storedName);

        return $attachable->attachments()->create([
            'attachment_category' => $category,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => '/uploads/quality/' . $category . '/' . $storedName,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by_user_id' => $actor?->id,
        ]);
    }

    protected function purgeAttachments(Collection $attachments): void
    {
        $attachments->each(function (QualityAttachment $attachment): void {
            $path = public_path(ltrim((string) $attachment->file_path, '/'));
            if ($attachment->file_path && File::exists($path)) {
                File::delete($path);
            }
            $attachment->delete();
        });
    }

    protected function nextReportNumber(): string
    {
        $prefix = 'ER-' . Carbon::now()->format('Y');
        $last = EightDReport::query()
            ->where('report_number', 'like', $prefix . '-%')
            ->orderByDesc('report_number')
            ->value('report_number');

        $next = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return sprintf('%s-%04d', $prefix, $next);
    }

    protected function nextIssueTrackingNumber(string $issueType): string
    {
        $normalizedType = strtolower(trim($issueType));
        $prefix = ($normalizedType === 'supplier' ? 'SR' : 'ER') . '-' . Carbon::now()->format('Ym');
        $last = QualityIssue::query()
            ->where('tracking_number', 'like', $prefix . '-%')
            ->orderByDesc('tracking_number')
            ->value('tracking_number');

        $next = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return sprintf('%s-%04d', $prefix, $next);
    }

    protected function nextVpdNumber(): string
    {
        $prefix = 'VPD-' . Carbon::now()->format('Y');
        $last = VpdClaim::query()
            ->where('vpd_number', 'like', $prefix . '-%')
            ->orderByDesc('vpd_number')
            ->value('vpd_number');

        $next = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return sprintf('%s-%04d', $prefix, $next);
    }

    protected function resolveKnownMaterialCode(mixed $value): ?string
    {
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }

        $knownCodes = WorkOrder::query()
            ->select(['item_code', 'material_1_code', 'material_2_code', 'material_3_code', 'material_4_code'])
            ->where(function ($query) {
                $query->whereNotNull('production_date_completed')
                    ->orWhereNotNull('completed_at')
                    ->orWhereRaw("LOWER(TRIM(COALESCE(status, ''))) = ?", ['completed']);
            })
            ->get()
            ->flatMap(fn (WorkOrder $order) => [
                $order->item_code,
                $order->material_1_code,
                $order->material_2_code,
                $order->material_3_code,
                $order->material_4_code,
            ])
            ->filter(fn ($item) => filled($item))
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->values();

        $matched = $knownCodes->first(fn (string $knownCode) => strcasecmp($knownCode, $code) === 0);
        if ($matched) {
            return $matched;
        }

        throw ValidationException::withMessages([
            'material_code' => 'Material code must match an existing completed-work-order material code.',
        ]);
    }

    protected function resolveCustomerId(array $data): ?int
    {
        if (! empty($data['customer_id'])) {
            return (int) $data['customer_id'];
        }

        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return Customer::query()
            ->where('customer_name', $name)
            ->value('id');
    }

    protected function resolveSupplierId(array $data): ?int
    {
        if (! empty($data['supplier_id'])) {
            return (int) $data['supplier_id'];
        }

        $name = trim((string) ($data['supplier_name'] ?? $data['vendor_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $existingId = Supplier::query()->where('supplier_name', $name)->value('id');
        if ($existingId) {
            return (int) $existingId;
        }

        return Supplier::query()->create([
            'supplier_name' => $name,
            'status' => 'active',
        ])->id;
    }

    protected function resolveWorkOrderId(array $data): ?int
    {
        if (! empty($data['work_order_id'])) {
            $candidate = WorkOrder::query()
                ->whereKey((int) $data['work_order_id'])
                ->where(function ($query) {
                    $query->whereNotNull('production_date_completed')
                        ->orWhereNotNull('completed_at')
                        ->orWhereRaw("LOWER(TRIM(COALESCE(status, ''))) = ?", ['completed']);
                })
                ->value('id');

            return $candidate ? (int) $candidate : null;
        }

        $workOrderNo = trim((string) ($data['work_order_no'] ?? ''));
        if ($workOrderNo === '') {
            return null;
        }

        return WorkOrder::query()
            ->where('work_order_no', $workOrderNo)
            ->where(function ($query) {
                $query->whereNotNull('production_date_completed')
                    ->orWhereNotNull('completed_at')
                    ->orWhereRaw("LOWER(TRIM(COALESCE(status, ''))) = ?", ['completed']);
            })
            ->value('id');
    }

    protected function resolveUserName($userId, ?string $fallback = null): ?string
    {
        if ($userId) {
            $user = User::query()->find($userId);
            if ($user) {
                $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $fallback = trim((string) $fallback);
        return $fallback !== '' ? $fallback : null;
    }

    protected function defaultEightDSteps(): array
    {
        return [
            ['step_code' => 'D1', 'title' => 'Team', 'approval_status' => 'Pending'],
            ['step_code' => 'D2', 'title' => 'Describe the Problem', 'approval_status' => 'Pending'],
            ['step_code' => 'D3', 'title' => 'Containment Plan', 'approval_status' => 'Pending'],
            ['step_code' => 'D4', 'title' => 'Root Cause', 'approval_status' => 'Pending'],
            ['step_code' => 'D5', 'title' => 'Corrective Action', 'approval_status' => 'Pending'],
            ['step_code' => 'D6', 'title' => 'Implement / Validate Permanent Corrective Action', 'approval_status' => 'Pending'],
            ['step_code' => 'D7', 'title' => 'Preventive Action Plan', 'approval_status' => 'Pending'],
            ['step_code' => 'D8', 'title' => 'Closure', 'approval_status' => 'Pending'],
        ];
    }

    protected function decimalOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function ingestAoiWorkbook(string $path, AoiImportBatch $batch): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $headerRow = 1;
        $headers = $sheet->rangeToArray('A' . $headerRow . ':' . $highestColumn . $headerRow, null, true, false)[0] ?? [];
        $mappedHeaders = collect($headers)->map(fn ($header, $index) => ['index' => $index, 'label' => trim((string) $header)])->values();

        $rowCount = 0;
        $importedCount = 0;
        $duplicateCount = 0;
        $mapping = $mappedHeaders->pluck('label', 'index')->all();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, null, true, false)[0] ?? [];
            if (! collect($values)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->count()) {
                continue;
            }

            $rowCount++;
            $record = [];
            foreach ($mappedHeaders as $header) {
                $record[$header['label']] = $values[$header['index']] ?? null;
            }

            $measurementTime = $this->parseSpreadsheetDate($this->valueFromSpreadsheetRow($record, ['Measurement Time', 'Date', 'Date Time', 'Inspection Time']));
            $serialCounter = trim((string) ($this->valueFromSpreadsheetRow($record, ['Serial Counter', 'S/N', 'Counter']) ?? ''));
            $lotNumber = trim((string) ($this->valueFromSpreadsheetRow($record, ['Lot Number', 'Lot No', 'Lot']) ?? ''));
            $programName = trim((string) ($this->valueFromSpreadsheetRow($record, ['Program', 'Program Name', 'Recipe']) ?? ''));

            $duplicateQuery = AoiMeasurementHeader::query()
                ->when($measurementTime, fn ($q) => $q->where('measurement_time', $measurementTime->toDateTimeString()))
                ->when($serialCounter !== '', fn ($q) => $q->where('serial_counter', $serialCounter))
                ->when($lotNumber !== '', fn ($q) => $q->where('lot_number', $lotNumber))
                ->when($programName !== '', fn ($q) => $q->where('program_name', $programName));

            if ($duplicateQuery->exists()) {
                $duplicateCount++;
                continue;
            }

            $workOrderNo = trim((string) ($this->valueFromSpreadsheetRow($record, ['Work Order', 'Work Order No', 'WO']) ?? ''));
            $customerName = trim((string) ($this->valueFromSpreadsheetRow($record, ['Customer']) ?? ''));
            $machineNumber = trim((string) ($this->valueFromSpreadsheetRow($record, ['Machine Number', 'Machine No', 'Machine']) ?? ''));
            $machineName = trim((string) ($this->valueFromSpreadsheetRow($record, ['Machine Name']) ?? ''));
            $operatorName = trim((string) ($this->valueFromSpreadsheetRow($record, ['Operator', 'Operator Name', 'Name']) ?? ''));

            $header = AoiMeasurementHeader::query()->create([
                'aoi_import_batch_id' => $batch->id,
                'measurement_time' => $measurementTime?->toDateTimeString(),
                'result_status' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Result', 'Result Status', 'OK/NG']) ?? '')),
                'lot_number' => $lotNumber !== '' ? $lotNumber : null,
                'serial_counter' => $serialCounter !== '' ? $serialCounter : null,
                'operator_name' => $operatorName !== '' ? $operatorName : null,
                'roll_id' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Roll ID', 'Roll']) ?? '')) ?: null,
                'machine_number' => $machineNumber !== '' ? $machineNumber : ($machineName !== '' ? $machineName : null),
                'computer_name' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Computer Name', 'Computer']) ?? '')) ?: null,
                'program_name' => $programName !== '' ? $programName : null,
                'work_order_id' => $workOrderNo !== '' ? WorkOrder::query()->where('work_order_no', $workOrderNo)->value('id') : null,
                'work_order_no' => $workOrderNo !== '' ? $workOrderNo : null,
                'customer_id' => $customerName !== '' ? Customer::query()->where('customer_name', $customerName)->value('id') : null,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'machine_id' => $this->resolveAoiMachineId($machineNumber, $machineName),
                'shift_name' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Shift']) ?? '')) ?: null,
                'part_number' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Part Number', 'Part No']) ?? '')) ?: null,
                'batch_number' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Batch Number', 'Batch']) ?? '')) ?: null,
                'error_code' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Error Code']) ?? '')) ?: null,
                'stop_reason' => trim((string) ($this->valueFromSpreadsheetRow($record, ['Stop Reason']) ?? '')) ?: null,
                'is_reinspection' => in_array(strtolower(trim((string) ($this->valueFromSpreadsheetRow($record, ['Reinspection', 'Is Reinspection']) ?? ''))), ['yes', 'true', '1'], true),
                'metadata' => ['raw_row' => $record],
            ]);

            foreach ($record as $column => $rawValue) {
                if (! preg_match('/^\[\d+\]/', (string) $column)) {
                    continue;
                }

                $code = trim((string) $column);
                $numericValue = $this->decimalOrNull($rawValue);
                $spec = MeasurementCharacteristicSpec::query()->firstOrCreate(
                    [
                        'part_number' => $header->part_number,
                        'program_name' => $header->program_name,
                        'characteristic_code' => $code,
                    ],
                    [
                        'customer_id' => $header->customer_id,
                        'work_order_id' => $header->work_order_id,
                        'characteristic_name' => $code,
                        'is_active' => true,
                    ]
                );

                $outOfSpec = false;
                if ($numericValue !== null) {
                    if ($spec->lower_spec_limit !== null && $numericValue < (float) $spec->lower_spec_limit) {
                        $outOfSpec = true;
                    }
                    if ($spec->upper_spec_limit !== null && $numericValue > (float) $spec->upper_spec_limit) {
                        $outOfSpec = true;
                    }
                }

                $header->details()->create([
                    'measurement_characteristic_spec_id' => $spec->id,
                    'characteristic_code' => $code,
                    'characteristic_name' => $spec->characteristic_name ?: $code,
                    'numeric_value' => $numericValue,
                    'raw_value' => $rawValue !== null ? (string) $rawValue : null,
                    'units' => $spec->units,
                    'nominal_value' => $spec->nominal_value,
                    'lower_spec_limit' => $spec->lower_spec_limit,
                    'upper_spec_limit' => $spec->upper_spec_limit,
                    'is_out_of_spec' => $outOfSpec,
                ]);
            }

            $importedCount++;
        }

        return [
            'row_count' => $rowCount,
            'imported_count' => $importedCount,
            'duplicate_count' => $duplicateCount,
            'mapping' => $mapping,
        ];
    }

    protected function valueFromSpreadsheetRow(array $row, array $candidateHeaders)
    {
        foreach ($candidateHeaders as $header) {
            if (array_key_exists($header, $row)) {
                return $row[$header];
            }
        }

        return null;
    }

    protected function parseSpreadsheetDate($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                return null;
            }
        }

        return CalibrationSchedule::parseDate($value);
    }

    protected function resolveAoiMachineId(string $machineNumber, string $machineName = ''): ?int
    {
        $machineNumber = trim($machineNumber);
        $machineName = trim($machineName);

        if ($machineNumber === '' && $machineName === '') {
            return null;
        }

        return Machine::query()
            ->when($machineNumber !== '', function ($query) use ($machineNumber) {
                $query->where('machine_no', $machineNumber)
                    ->orWhere('machine_name', $machineNumber);
            })
            ->when($machineName !== '', function ($query) use ($machineName, $machineNumber) {
                if ($machineNumber !== '') {
                    $query->orWhere('machine_name', $machineName);
                    return;
                }

                $query->where('machine_name', $machineName);
            })
            ->value('id');
    }
}
