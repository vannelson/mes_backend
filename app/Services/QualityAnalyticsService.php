<?php

namespace App\Services;

use App\Models\AoiMeasurementDetail;
use App\Models\AoiMeasurementHeader;
use App\Models\CalibrationMaster;
use App\Models\EightDReport;
use App\Models\QualityAnalyticsChart;
use App\Models\QualityAnalyticsRuleViolation;
use App\Models\QualityAnalyticsRun;
use App\Models\QualityFollowUpLot;
use App\Models\QualityIssue;
use App\Models\SupplierChangeControl;
use App\Models\User;
use App\Models\VpdClaim;
use App\Support\QualityAnalyticsNativeEngine;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class QualityAnalyticsService
{
    public function __construct(
        protected QualityManagementService $qualityManagementService,
        protected QualityAnalyticsNativeEngine $nativeEngine
    ) {
    }

    public function generate(array $filters = [], ?User $actor = null, bool $force = false): array
    {
        if (! $force) {
            $latest = $this->latestSuccessfulRun();
            if ($latest && empty($filters)) {
                return $this->transformRun($latest);
            }
        }

        $normalizedFilters = $this->normalizeFilters($filters);
        $run = QualityAnalyticsRun::query()->create([
            'scope' => 'quality_reporting',
            'engine_name' => 'native-php-spc-engine',
            'engine_version' => 'pending',
            'status' => 'processing',
            'requested_by_user_id' => $actor?->id,
            'started_at' => now(),
            'filters' => $normalizedFilters,
            'metadata' => [
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        try {
            $result = $this->executeAnalytics($run, $normalizedFilters);
            $this->persistRunResult($run, $result);

            return $this->transformRun($run->fresh(['charts', 'ruleViolations', 'sourceLinks']));
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function latestSuccessfulRun(): ?QualityAnalyticsRun
    {
        return QualityAnalyticsRun::query()
            ->with(['charts', 'ruleViolations', 'sourceLinks'])
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    public function transformRun(?QualityAnalyticsRun $run): array
    {
        if (! $run) {
            return [
                'run_id' => null,
                'status' => 'empty',
                'generated_at' => null,
                'filters' => [],
                'summary_metrics' => [],
                'capability_results' => [],
                'rule_summary' => [],
                'modules' => [
                    'dashboard' => ['charts' => [], 'messages' => []],
                    'aoi_spc' => ['charts' => [], 'messages' => ['No analytics run available yet.']],
                ],
            ];
        }

        $charts = $run->charts
            ->sortBy(fn (QualityAnalyticsChart $chart) => sprintf('%s:%s', $chart->module_key, $chart->chart_key))
            ->values();

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'generated_at' => optional($run->completed_at)->toIso8601String(),
            'filters' => $run->filters ?? [],
            'summary_metrics' => $run->summary_metrics ?? [],
            'capability_results' => $run->capability_results ?? [],
            'rule_summary' => $run->rule_summary ?? [],
            'metadata' => $run->metadata ?? [],
            'modules' => [
                'dashboard' => [
                    'charts' => $this->transformCharts($charts->where('module_key', 'dashboard')),
                    'messages' => Arr::wrap(data_get($run->metadata, 'dashboard_messages', [])),
                ],
                'aoi_spc' => [
                    'charts' => $this->transformCharts($charts->where('module_key', 'aoi_spc')),
                    'messages' => Arr::wrap(data_get($run->metadata, 'aoi_messages', [])),
                    'measurement_health' => data_get($run->metadata, 'measurement_health', []),
                    'selected_characteristic' => data_get($run->metadata, 'selected_characteristic'),
                    'rule_violations' => $run->ruleViolations
                        ->sortByDesc('severity')
                        ->values()
                        ->map(fn (QualityAnalyticsRuleViolation $violation) => [
                            'id' => $violation->id,
                            'chart_id' => $violation->quality_analytics_chart_id,
                            'rule_code' => $violation->rule_code,
                            'severity' => $violation->severity,
                            'message' => $violation->message,
                            'context' => $violation->context,
                        ])->all(),
                ],
            ],
        ];
    }

    protected function transformCharts(Collection $charts): array
    {
        return $charts->values()->map(function (QualityAnalyticsChart $chart): array {
            return [
                'id' => $chart->id,
                'chart_key' => $chart->chart_key,
                'chart_type' => $chart->chart_type,
                'title' => $chart->title,
                'image_url' => $this->publicUrl($chart->image_path),
                'image_path' => $chart->image_path,
                'is_spc' => $chart->is_spc,
                'series_payload' => $chart->series_payload ?? [],
                'stat_payload' => $chart->stat_payload ?? [],
                'metadata' => $chart->metadata ?? [],
            ];
        })->all();
    }

    protected function executeAnalytics(QualityAnalyticsRun $run, array $filters): array
    {
        return $this->nativeEngine->generate($this->buildPayload($filters));
    }

    protected function persistRunResult(QualityAnalyticsRun $run, array $result): void
    {
        DB::transaction(function () use ($run, $result): void {
            $run->charts()->delete();
            $run->ruleViolations()->delete();
            $run->sourceLinks()->delete();

            $run->update([
                'engine_name' => $result['engine_name'] ?? 'native-php-spc-engine',
                'engine_version' => $result['engine_version'] ?? '1.0.0',
                'status' => 'completed',
                'completed_at' => now(),
                'summary_metrics' => $result['summary_metrics'] ?? [],
                'capability_results' => $result['capability_results'] ?? [],
                'rule_summary' => $result['rule_summary'] ?? [],
                'metadata' => $result['metadata'] ?? [],
                'error_message' => null,
            ]);

            $moduleSections = $result['modules'] ?? [];
            foreach ($moduleSections as $moduleKey => $moduleSection) {
                foreach (($moduleSection['charts'] ?? []) as $chartPayload) {
                    $relativePath = ! empty($chartPayload['filename'])
                        ? '/uploads/quality/analytics/run_' . $run->id . '/' . $chartPayload['filename']
                        : null;
                    $absolutePath = $relativePath ? public_path(ltrim($relativePath, '/')) : null;

                    $chart = $run->charts()->create([
                        'module_key' => $moduleKey,
                        'chart_key' => $chartPayload['chart_key'],
                        'chart_type' => $chartPayload['chart_type'] ?? 'chart',
                        'title' => $chartPayload['title'] ?? $chartPayload['chart_key'],
                        'image_path' => $relativePath,
                        'mime_type' => $absolutePath ? $this->mimeTypeForPath($absolutePath) : null,
                        'file_size' => $absolutePath ? $this->fileSizeOrNull($absolutePath) : null,
                        'is_spc' => (bool) ($chartPayload['is_spc'] ?? false),
                        'filters' => $run->filters ?? [],
                        'series_payload' => $chartPayload['series_payload'] ?? [],
                        'stat_payload' => $chartPayload['stat_payload'] ?? [],
                        'metadata' => $chartPayload['metadata'] ?? [],
                    ]);

                    foreach (($chartPayload['rule_violations'] ?? []) as $violation) {
                        $run->ruleViolations()->create([
                            'quality_analytics_chart_id' => $chart->id,
                            'module_key' => $moduleKey,
                            'rule_code' => $violation['rule_code'] ?? 'UNKNOWN_RULE',
                            'severity' => $violation['severity'] ?? 'medium',
                            'message' => $violation['message'] ?? 'Rule violation detected.',
                            'context' => $violation['context'] ?? [],
                        ]);
                    }

                    foreach (($chartPayload['source_links'] ?? []) as $link) {
                        $run->sourceLinks()->create([
                            'quality_analytics_chart_id' => $chart->id,
                            'source_module' => $link['source_module'] ?? $moduleKey,
                            'source_type' => $link['source_type'] ?? 'record',
                            'source_id' => (int) ($link['source_id'] ?? 0),
                            'metadata' => $link['metadata'] ?? [],
                        ]);
                    }
                }
            }
        });
    }

    protected function buildPayload(array $filters): array
    {
        $issues = $this->qualityManagementService->applyIssueFilters(QualityIssue::query()->with('followUpLots'), $filters)->get();
        $aoiHeaders = $this->qualityManagementService->applyAoiFilters(AoiMeasurementHeader::query(), $filters)->get();
        $aoiDetails = $this->buildAoiDetailQuery($filters)->get();
        $claims = VpdClaim::query()
            ->when($filters['supplier'] ?? null, fn ($query, $value) => $query->where('vendor_name', 'like', '%' . $value . '%'))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('claim_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('claim_date', '<=', $value))
            ->get();
        $reports = EightDReport::query()
            ->with('qualityIssue')
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['severity'] ?? null, fn ($query, $value) => $query->where('severity', $value))
            ->get();
        $followUpLots = QualityFollowUpLot::query()
            ->whereHas('qualityIssue', fn ($query) => $this->qualityManagementService->applyIssueFilters($query, $filters))
            ->get();
        $calibrations = CalibrationMaster::query()->where('is_active', true)->get();
        $supplierChanges = SupplierChangeControl::query()->get();

        $selectedCharacteristic = $filters['characteristic'] ?? $this->inferTopCharacteristic($aoiDetails);

        return [
            'filters' => $filters,
            'summary_metrics' => $this->summaryMetrics($issues, $aoiHeaders, $claims, $calibrations, $reports, $followUpLots),
            'dashboard' => [
                'issue_trends' => $this->issueTrendSeries($issues),
                'defect_pareto' => $this->defectParetoSeries($issues),
                'machine_rankings' => $this->machineRankingSeries($aoiHeaders),
                'operator_rankings' => $this->operatorRankingSeries($aoiHeaders),
                'calibration_compliance' => $this->calibrationComplianceSeries($calibrations),
                'vpd_claim_trends' => $this->vpdClaimTrendSeries($claims),
                'supplier_claim_pareto' => $this->supplierClaimParetoSeries($claims),
                'capa_aging' => $this->capaAgingSeries($reports, $supplierChanges),
                'follow_up_validation' => $this->followUpValidationSeries($followUpLots),
                'aoi_pass_fail' => $this->aoiPassFailSeries($aoiHeaders),
            ],
            'aoi_spc' => [
                'selected_characteristic' => $selectedCharacteristic,
                'points' => $this->aoiSpcPoints($aoiDetails, $selectedCharacteristic),
            ],
        ];
    }

    protected function normalizeFilters(array $filters): array
    {
        return collect($filters)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->sortKeys()
            ->all();
    }

    protected function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return rtrim(config('app.url'), '/') . $path;
    }

    protected function fileSizeOrNull(string $path): ?int
    {
        return is_file($path) ? filesize($path) ?: null : null;
    }

    protected function mimeTypeForPath(string $path): string
    {
        return str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'image/png';
    }

    protected function buildAoiDetailQuery(array $filters)
    {
        return AoiMeasurementDetail::query()
            ->with('header')
            ->whereHas('header', fn ($query) => $this->qualityManagementService->applyAoiFilters($query, $filters))
            ->when($filters['characteristic'] ?? null, function ($query, $value) {
                $query->where(function ($inner) use ($value) {
                    $inner->where('characteristic_code', 'like', '%' . $value . '%')
                        ->orWhere('characteristic_name', 'like', '%' . $value . '%');
                });
            })
            ->when($filters['inspection_result'] ?? null, function ($query, $value) {
                $query->whereHas('header', fn ($inner) => $inner->where('result_status', $value));
            });
    }

    protected function inferTopCharacteristic(Collection $details): ?string
    {
        return $details
            ->filter(fn (AoiMeasurementDetail $detail) => $detail->numeric_value !== null)
            ->groupBy(fn (AoiMeasurementDetail $detail) => $detail->characteristic_code ?: $detail->characteristic_name ?: 'Unspecified')
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->keys()
            ->first();
    }

    protected function aoiSpcPoints(Collection $details, ?string $characteristic): array
    {
        return $details
            ->filter(function (AoiMeasurementDetail $detail) use ($characteristic): bool {
                if (! $characteristic) {
                    return true;
                }

                return in_array($characteristic, [$detail->characteristic_code, $detail->characteristic_name], true);
            })
            ->sortBy(fn (AoiMeasurementDetail $detail) => optional($detail->header?->measurement_time)->timestamp ?? 0)
            ->values()
            ->map(function (AoiMeasurementDetail $detail): array {
                $header = $detail->header;
                return [
                    'detail_id' => $detail->id,
                    'header_id' => $detail->aoi_measurement_header_id,
                    'measurement_time' => optional($header?->measurement_time)->toIso8601String(),
                    'value' => $detail->numeric_value,
                    'lsl' => $detail->lower_spec_limit,
                    'usl' => $detail->upper_spec_limit,
                    'nominal' => $detail->nominal_value,
                    'result_status' => $header?->result_status,
                    'characteristic_code' => $detail->characteristic_code,
                    'characteristic_name' => $detail->characteristic_name,
                    'machine' => $header?->machine_number,
                    'operator' => $header?->operator_name,
                    'work_order_no' => $header?->work_order_no,
                    'lot_number' => $header?->lot_number,
                    'batch_number' => $header?->batch_number,
                    'part_number' => $header?->part_number,
                    'subgroup_key' => $header?->lot_number ?: $header?->batch_number ?: $header?->shift_name ?: $header?->work_order_no ?: 'Ungrouped',
                    'is_out_of_spec' => $detail->is_out_of_spec,
                ];
            })->all();
    }

    protected function summaryMetrics(Collection $issues, Collection $aoiHeaders, Collection $claims, Collection $calibrations, Collection $reports, Collection $followUpLots): array
    {
        $totalInspections = max($aoiHeaders->count(), 1);
        $okCount = $aoiHeaders->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'OK')->count();
        $ngCount = $aoiHeaders->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'NG')->count();
        $acceptedLots = $aoiHeaders->groupBy(fn (AoiMeasurementHeader $header) => $header->lot_number ?: $header->batch_number ?: 'unknown')
            ->filter(fn (Collection $group) => $group->every(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) !== 'NG'))
            ->count();
        $lotTotal = max($aoiHeaders->groupBy(fn (AoiMeasurementHeader $header) => $header->lot_number ?: $header->batch_number ?: 'unknown')->count(), 1);
        $calibrationCompliant = $calibrations->filter(fn (CalibrationMaster $item) => $item->next_calibration_date && $item->next_calibration_date->gte(today()))->count();
        $followUpPass = $followUpLots->filter(fn (QualityFollowUpLot $lot) => strtoupper((string) $lot->result_status) === 'PASS')->count();

        return [
            'first_pass_yield' => round(($okCount / $totalInspections) * 100, 2),
            'rejection_rate' => round(($ngCount / $totalInspections) * 100, 2),
            'rework_rate' => round(($aoiHeaders->where('is_reinspection', true)->count() / $totalInspections) * 100, 2),
            'dppm' => round(($ngCount / $totalInspections) * 1000000, 2),
            'inspection_lot_acceptance_rate' => round(($acceptedLots / $lotTotal) * 100, 2),
            'customer_complaint_frequency' => $issues->where('issue_type', 'customer')->count(),
            'supplier_quality_performance' => round(max(0, 100 - ($issues->where('issue_type', 'supplier')->count() * 2)), 2),
            'ncr_capa_effectiveness' => $followUpLots->count() ? round(($followUpPass / $followUpLots->count()) * 100, 2) : null,
            'calibration_compliance_rate' => $calibrations->count() ? round(($calibrationCompliant / $calibrations->count()) * 100, 2) : null,
            'aoi_pass_fail_ratio' => $ngCount > 0 ? round($okCount / $ngCount, 2) : $okCount,
            'average_case_turnaround_days' => round($issues->avg('actual_tat_days') ?? 0, 2),
            'open_8d_reports' => $reports->filter(fn (EightDReport $report) => strtolower((string) $report->status) !== 'closed')->count(),
            'vpd_claim_total_sgd' => round((float) $claims->sum(fn (VpdClaim $claim) => (float) ($claim->total_sgd ?? 0)), 2),
        ];
    }

    protected function issueTrendSeries(Collection $issues): array
    {
        $periods = $issues->groupBy(fn (QualityIssue $issue) => optional($issue->date_issue)->format('Y-m') ?: 'Unknown');
        return $periods->map(function (Collection $group, string $period): array {
            return [
                'period' => $period,
                'customer_count' => $group->where('issue_type', 'customer')->count(),
                'supplier_count' => $group->where('issue_type', 'supplier')->count(),
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'quality_issues', 'issue'),
            ];
        })->values()->all();
    }

    protected function defectParetoSeries(Collection $issues): array
    {
        return $issues->groupBy(fn (QualityIssue $issue) => trim((string) ($issue->problem_statement ?: 'Unspecified')))
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(10)
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'count' => $group->count(),
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'quality_issues', 'issue'),
            ])->values()->all();
    }

    protected function machineRankingSeries(Collection $headers): array
    {
        return $headers->groupBy(fn (AoiMeasurementHeader $header) => $header->machine_number ?: 'Unspecified')
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'ng_count' => $group->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'NG')->count(),
                'total_count' => $group->count(),
                'pass_rate' => $group->count() ? round(($group->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'OK')->count() / $group->count()) * 100, 2) : 0,
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'aoi_measurements', 'header'),
            ])->sortByDesc('ng_count')->take(10)->values()->all();
    }

    protected function operatorRankingSeries(Collection $headers): array
    {
        return $headers->groupBy(fn (AoiMeasurementHeader $header) => $header->operator_name ?: 'Unspecified')
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'ng_count' => $group->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'NG')->count(),
                'total_count' => $group->count(),
                'pass_rate' => $group->count() ? round(($group->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'OK')->count() / $group->count()) * 100, 2) : 0,
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'aoi_measurements', 'header'),
            ])->sortByDesc('ng_count')->take(10)->values()->all();
    }

    protected function calibrationComplianceSeries(Collection $calibrations): array
    {
        $today = today();
        $series = [
            ['label' => 'Compliant', 'count' => 0, 'ids' => []],
            ['label' => 'Due Soon', 'count' => 0, 'ids' => []],
            ['label' => 'Overdue', 'count' => 0, 'ids' => []],
        ];
        foreach ($calibrations as $item) {
            if (! $item->next_calibration_date) {
                continue;
            }
            if ($item->next_calibration_date->lt($today)) {
                $series[2]['count']++;
                $series[2]['ids'][] = $item->id;
            } elseif ($item->next_calibration_date->lte($today->copy()->addDays(30))) {
                $series[1]['count']++;
                $series[1]['ids'][] = $item->id;
            } else {
                $series[0]['count']++;
                $series[0]['ids'][] = $item->id;
            }
        }

        return collect($series)->map(fn (array $row) => [
            'label' => $row['label'],
            'count' => $row['count'],
            'source_links' => $this->linksFromIds($row['ids'], 'calibration_master', 'calibration'),
        ])->all();
    }

    protected function vpdClaimTrendSeries(Collection $claims): array
    {
        return $claims->groupBy(fn (VpdClaim $claim) => optional($claim->claim_date)->format('Y-m') ?: 'Unknown')
            ->map(fn (Collection $group, string $period) => [
                'label' => $period,
                'total_amount' => round((float) $group->sum(fn (VpdClaim $claim) => (float) ($claim->total_sgd ?? 0)), 2),
                'count' => $group->count(),
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'vpd_claims', 'claim'),
            ])->values()->all();
    }

    protected function supplierClaimParetoSeries(Collection $claims): array
    {
        return $claims->groupBy(fn (VpdClaim $claim) => $claim->vendor_name ?: 'Unspecified')
            ->sortByDesc(fn (Collection $group) => $group->sum(fn (VpdClaim $claim) => (float) ($claim->total_sgd ?? 0)))
            ->take(10)
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'count' => $group->count(),
                'total_amount' => round((float) $group->sum(fn (VpdClaim $claim) => (float) ($claim->total_sgd ?? 0)), 2),
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'vpd_claims', 'claim'),
            ])->values()->all();
    }

    protected function capaAgingSeries(Collection $reports, Collection $supplierChanges): array
    {
        $today = now();
        $series = collect([
            ['label' => '0-7 days', 'count' => 0, 'links' => []],
            ['label' => '8-14 days', 'count' => 0, 'links' => []],
            ['label' => '15-30 days', 'count' => 0, 'links' => []],
            ['label' => '31+ days', 'count' => 0, 'links' => []],
        ]);

        foreach ($reports->filter(fn (EightDReport $report) => strtolower((string) $report->status) !== 'closed') as $report) {
            $age = optional($report->date_issue)->diffInDays($today) ?? 0;
            $bucketIndex = $age <= 7 ? 0 : ($age <= 14 ? 1 : ($age <= 30 ? 2 : 3));
            $row = $series[$bucketIndex];
            $row['count']++;
            $row['links'][] = ['source_module' => '8d_reports', 'source_type' => 'report', 'source_id' => $report->id, 'metadata' => ['age_days' => $age]];
            $series[$bucketIndex] = $row;
        }

        foreach ($supplierChanges->filter(fn (SupplierChangeControl $control) => strtolower((string) $control->status) !== 'closed') as $control) {
            $age = optional($control->created_at)->diffInDays($today) ?? 0;
            $bucketIndex = $age <= 7 ? 0 : ($age <= 14 ? 1 : ($age <= 30 ? 2 : 3));
            $row = $series[$bucketIndex];
            $row['count']++;
            $row['links'][] = ['source_module' => 'supplier_change_control', 'source_type' => 'change_control', 'source_id' => $control->id, 'metadata' => ['age_days' => $age]];
            $series[$bucketIndex] = $row;
        }

        return $series->map(fn (array $row) => [
            'label' => $row['label'],
            'count' => $row['count'],
            'source_links' => $row['links'],
        ])->all();
    }

    protected function followUpValidationSeries(Collection $lots): array
    {
        return $lots->groupBy(fn (QualityFollowUpLot $lot) => (int) ($lot->sequence_no ?: 0))
            ->sortKeys()
            ->map(fn (Collection $group, int $sequence) => [
                'label' => sprintf('%d%s CAPA Lot', $sequence, $sequence === 1 ? 'st' : ($sequence === 2 ? 'nd' : ($sequence === 3 ? 'rd' : 'th'))),
                'pass_count' => $group->filter(fn (QualityFollowUpLot $lot) => strtoupper((string) $lot->result_status) === 'PASS')->count(),
                'fail_count' => $group->filter(fn (QualityFollowUpLot $lot) => strtoupper((string) $lot->result_status) !== 'PASS')->count(),
                'source_links' => $this->linksFromIds($group->pluck('id')->all(), 'quality_follow_up_lots', 'follow_up_lot'),
            ])->values()->all();
    }

    protected function aoiPassFailSeries(Collection $headers): array
    {
        return [
            [
                'label' => 'OK',
                'count' => $headers->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'OK')->count(),
                'source_links' => $this->linksFromIds($headers->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'OK')->pluck('id')->all(), 'aoi_measurements', 'header'),
            ],
            [
                'label' => 'NG',
                'count' => $headers->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'NG')->count(),
                'source_links' => $this->linksFromIds($headers->filter(fn (AoiMeasurementHeader $header) => strtoupper((string) $header->result_status) === 'NG')->pluck('id')->all(), 'aoi_measurements', 'header'),
            ],
        ];
    }

    protected function linksFromIds(array $ids, string $module, string $type): array
    {
        return collect($ids)
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id) => [
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => (int) $id,
                'metadata' => [],
            ])->all();
    }
}
