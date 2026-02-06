<?php

namespace Tests\Feature;

use App\Models\TemplateRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TemplateRouteImportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_endpoint_returns_summary_and_errors(): void
    {
        $user = User::factory()->create();
        $file = $this->makeSpreadsheetFile([
            ['Customer Part Number', 'Work Order Line No.', 'WO Journal Line No.', 'Machine Type', 'Machine Code', 'Machine Name', 'Process No.', 'Posted'],
            ['PART-A', 10000, 100, 'DIGITAL', 'D1', 'Digital One', 1, 'yes'],
            ['PART-A', 10000, 200, 'FLEXO', 'F1', 'Flexo A', 2, 'yes'],
            ['PART-A', 10000, 'BAD', 'UNKNOWN', 'F9', 'Flexo B', 3, 'yes'],
        ], 'Routes');

        $response = $this->actingAs($user)->post('/api/admin/template-routes/preview', [
            'file' => $file,
            'sheet' => 'Routes',
            'user_id' => $user->id,
            'dry_run' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.summary.rows_total', 3);
        $response->assertJsonPath('data.summary.rows_valid', 2);
        $response->assertJsonPath('data.summary.templates_count', 1);
        $response->assertJsonCount(1, 'data.templates');
        $response->assertJsonCount(1, 'data.errors');
    }

    public function test_replace_endpoint_persists_templates_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $file = $this->makeSpreadsheetFile([
            ['Customer Part Number', 'Work Order Line No.', 'WO Journal Line No.', 'Machine Type', 'Machine Code', 'Machine Name', 'Process No.', 'Posted'],
            ['PART-A', 10000, 100, 'DIGITAL', 'D1', 'Digital One', 1, 'yes'],
            ['PART-A', 10000, 200, 'FLEXO', 'F1', 'Flexo A', 2, 'yes'],
            ['PART-B', 10000, 100, 'DIGITAL', 'D1', 'Digital One', 1, 'yes'],
            ['PART-B', 10000, 200, 'FLEXO', 'F9', 'Flexo B', 2, 'yes'],
        ], 'Routes');

        $batchNumber = 'BATCH-001';

        $response = $this->actingAs($user)->post('/api/admin/template-routes/replace', [
            'file' => $file,
            'sheet' => 'Routes',
            'user_id' => $user->id,
            'dry_run' => false,
            'batch_number' => $batchNumber,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, TemplateRoute::where('batch_number', $batchNumber)->count());
        $template = TemplateRoute::where('batch_number', $batchNumber)->firstOrFail();
        $this->assertSame('DIGITAL->FLEXO', $template->template);
        $this->assertIsArray($template->metadata);
        $this->assertNotEmpty($template->metadata);
        $this->assertArrayHasKey('routes', $template->metadata[0]);
        $this->assertArrayHasKey('parameters', $template->metadata[0]['routes'][0]);

        $secondFile = $this->makeSpreadsheetFile([
            ['Customer Part Number', 'Work Order Line No.', 'WO Journal Line No.', 'Machine Type', 'Machine Code', 'Machine Name', 'Process No.', 'Posted'],
            ['PART-A', 10000, 100, 'DIGITAL', 'D1', 'Digital One', 1, 'yes'],
            ['PART-A', 10000, 200, 'FLEXO', 'F1', 'Flexo A', 2, 'yes'],
            ['PART-B', 10000, 100, 'DIGITAL', 'D1', 'Digital One', 1, 'yes'],
            ['PART-B', 10000, 200, 'FLEXO', 'F9', 'Flexo B', 2, 'yes'],
        ], 'Routes');

        $this->actingAs($user)->post('/api/admin/template-routes/replace', [
            'file' => $secondFile,
            'sheet' => 'Routes',
            'user_id' => $user->id,
            'dry_run' => false,
            'batch_number' => $batchNumber,
        ]);

        $this->assertSame(1, TemplateRoute::where('batch_number', $batchNumber)->count());
    }

    private function makeSpreadsheetFile(array $rows, string $sheetName): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray($rows, null, 'A1', true);

        $tempPath = tempnam(sys_get_temp_dir(), 'tpl');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'template_routes.xlsx', null, null, true);
    }
}
