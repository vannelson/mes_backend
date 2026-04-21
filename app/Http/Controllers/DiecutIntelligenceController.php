<?php

namespace App\Http\Controllers;

use App\Services\DiecutIntelligenceService;
use App\Services\DiecutWorkbookImportService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DiecutIntelligenceController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected DiecutIntelligenceService $intelligenceService,
        protected DiecutWorkbookImportService $workbookImportService
    ) {
    }

    public function estimate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'diecut_no' => ['nullable', 'string', 'max:180'],
            'die_cut' => ['nullable', 'string', 'max:180'],
            'customer_part_number' => ['nullable', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric'],
            'quantity_to_produce' => ['nullable', 'numeric'],
            'forecast_quantity' => ['nullable', 'numeric'],
            'machine_no' => ['nullable', 'string', 'max:120'],
            'machine_name' => ['nullable', 'string', 'max:255'],
            'machine_speed' => ['nullable', 'numeric'],
        ]);

        return $this->success('Diecut duration estimate calculated successfully!', $this->intelligenceService->estimateDuration($payload));
    }

    public function toolingSummary(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'diecut_no' => ['nullable', 'string', 'max:180'],
            'customer_part_number' => ['nullable', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:120'],
        ]);

        $profile = $this->intelligenceService->resolveProfile(
            $payload['diecut_no'] ?? null,
            $payload['customer_part_number'] ?? null,
            $payload['customer_code'] ?? null
        );

        return $this->success('Diecut tooling summary retrieved successfully!', [
            'profile' => $profile ? [
                'id' => $profile->id,
                'profile_code' => $profile->profile_code,
                'diecut_type' => $profile->diecut_type,
            ] : null,
            'tooling' => $this->intelligenceService->summarizeTooling($profile),
        ]);
    }

    public function importRoutingWorkbook(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xlsm,xls'],
            'batch_number' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            return $this->success(
                'Diecut routing workbook imported successfully!',
                $this->workbookImportService->importRoutingWorkbook($payload['file'], $payload['batch_number'] ?? null)
            );
        } catch (Throwable) {
            return $this->error('Failed to import diecut routing workbook.', 500);
        }
    }

    public function importToolingWorkbook(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xlsm,xls'],
            'batch_number' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            return $this->success(
                'Diecut tooling workbook imported successfully!',
                $this->workbookImportService->importToolingWorkbook($payload['file'], $payload['batch_number'] ?? null)
            );
        } catch (Throwable) {
            return $this->error('Failed to import diecut tooling workbook.', 500);
        }
    }
}
