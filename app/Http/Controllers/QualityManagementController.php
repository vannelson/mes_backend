<?php

namespace App\Http\Controllers;

use App\Services\QualityManagementService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QualityManagementController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected QualityManagementService $qualityManagementService
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            return $this->success('Quality dashboard loaded successfully!', $this->qualityManagementService->dashboard($request->all()));
        } catch (Throwable $e) {
            return $this->error('Failed to load quality dashboard.', 500);
        }
    }

    public function issues(Request $request): JsonResponse
    {
        try {
            return $this->success('Quality issues loaded successfully!', $this->qualityManagementService->listIssues(
                $request->all(),
                (int) $request->get('limit', 25),
                (int) $request->get('page', 1)
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to load quality issues.', 500);
        }
    }

    public function storeIssue(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('Quality issue saved successfully!', $this->qualityManagementService->saveIssue(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                null,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to save quality issue.', 500);
        }
    }

    public function updateIssue(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('Quality issue updated successfully!', $this->qualityManagementService->saveIssue(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                $id,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to update quality issue.', 500);
        }
    }

    public function destroyIssue(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->qualityManagementService->deleteIssue($id);
            return $this->success('Quality issue deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete quality issue.', 500);
        }
    }

    public function eightDReports(Request $request): JsonResponse
    {
        try {
            return $this->success('8D reports loaded successfully!', $this->qualityManagementService->listEightDReports(
                $request->all(),
                (int) $request->get('limit', 25),
                (int) $request->get('page', 1)
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to load 8D reports.', 500);
        }
    }

    public function storeEightDReport(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('8D report saved successfully!', $this->qualityManagementService->saveEightDReport(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                null,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to save 8D report.', 500);
        }
    }

    public function updateEightDReport(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('8D report updated successfully!', $this->qualityManagementService->saveEightDReport(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                $id,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to update 8D report.', 500);
        }
    }

    public function destroyEightDReport(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->qualityManagementService->deleteEightDReport($id);
            return $this->success('8D report deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete 8D report.', 500);
        }
    }

    public function vpdClaims(Request $request): JsonResponse
    {
        try {
            return $this->success('VPD claims loaded successfully!', $this->qualityManagementService->listVpdClaims(
                $request->all(),
                (int) $request->get('limit', 25),
                (int) $request->get('page', 1)
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to load VPD claims.', 500);
        }
    }

    public function storeVpdClaim(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('VPD claim saved successfully!', $this->qualityManagementService->saveVpdClaim(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                null,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to save VPD claim.', 500);
        }
    }

    public function updateVpdClaim(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            return $this->success('VPD claim updated successfully!', $this->qualityManagementService->saveVpdClaim(
                array_merge($request->all(), ['attachment_files' => $request->file('attachments', [])]),
                $id,
                $request->user()
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to update VPD claim.', 500);
        }
    }

    public function destroyVpdClaim(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->qualityManagementService->deleteVpdClaim($id);
            return $this->success('VPD claim deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete VPD claim.', 500);
        }
    }

    public function aoiMeasurements(Request $request): JsonResponse
    {
        try {
            return $this->success('AOI measurements loaded successfully!', $this->qualityManagementService->listAoiMeasurements(
                $request->all(),
                (int) $request->get('limit', 50),
                (int) $request->get('page', 1)
            ));
        } catch (Throwable $e) {
            return $this->error('Failed to load AOI measurements.', 500);
        }
    }

    public function importAoi(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        $file = $request->file('file');
        if (! $file) {
            return $this->error('AOI file is required.', 422);
        }

        try {
            return $this->success('AOI import completed successfully!', $this->qualityManagementService->importAoiWorkbook($file, $request->user()));
        } catch (Throwable $e) {
            return $this->error('Failed to import AOI workbook.', 500);
        }
    }

    public function destroyAoiMeasurement(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->qualityManagementService->deleteAoiMeasurement($id);
            return $this->success('AOI measurement deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete AOI measurement.', 500);
        }
    }

    public function filterOptions(): JsonResponse
    {
        try {
            return $this->success('Quality filter options loaded successfully!', $this->qualityManagementService->filterOptions());
        } catch (Throwable $e) {
            return $this->error('Failed to load quality filters.', 500);
        }
    }

    protected function canManage(Request $request): bool
    {
        $role = strtolower(trim((string) ($request->user()?->user_type ?? $request->user()?->role ?? '')));

        return in_array($role, ['qa', 'qa engineer', 'senior qa engineer', 'qa supervisor', 'supervisor', 'manager', 'admin', 'superadmin'], true);
    }
}
