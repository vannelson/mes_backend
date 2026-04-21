<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\UploadedFile;
use App\Services\DiecutWorkbookImportService;
use App\Services\TriggerEmailService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mailtrap:test {--to=} {--subject=}', function (TriggerEmailService $emailService) {
    $to = $this->option('to')
        ?: env('MAILTRAP_TEST_TO')
        ?: env('MAIL_FROM_ADDRESS')
        ?: env('MAIL_FROM');
    if (! $to) {
        $this->error('Missing recipient. Provide --to or MAILTRAP_TEST_TO.');
        return 1;
    }

    $subject = $this->option('subject') ?: 'MES Mailtrap Test';
    $body = 'Mailtrap test from MES at ' . now()->toDateTimeString();

    try {
        $result = $emailService->send([
            'to' => $to,
            'subject' => $subject,
            'html' => '<p>' . e($body) . '</p>',
            'text' => $body,
            'category' => env('MAILTRAP_CATEGORY', 'MES Automation'),
        ]);
        $this->info('Mailtrap send OK.');
        $this->line(json_encode($result));
        return 0;
    } catch (\Throwable $e) {
        $this->error('Mailtrap send failed: ' . $e->getMessage());
        return 1;
    }
})->purpose('Send a Mailtrap test email');

Artisan::command('diecut:import-workbooks {--routing=} {--tooling=} {--batch=}', function (DiecutWorkbookImportService $importService) {
    $batch = $this->option('batch') ?: now()->format('dmy\THi');
    $results = [];

    foreach (['routing' => 'importRoutingWorkbook', 'tooling' => 'importToolingWorkbook'] as $option => $method) {
        $path = $this->option($option);
        if (!$path) {
            continue;
        }

        if (!is_file($path)) {
            $this->error("{$option} workbook not found: {$path}");
            return 1;
        }

        $file = new UploadedFile($path, basename($path), null, null, true);
        $results[$option] = $importService->{$method}($file, $batch);
        $this->info("Imported {$option} workbook.");
        $this->line(json_encode($results[$option], JSON_PRETTY_PRINT));
    }

    if (empty($results)) {
        $this->warn('No workbook paths supplied. Use --routing= and/or --tooling=.');
        return 1;
    }

    return 0;
})->purpose('Import DIECUT routing and tooling Excel workbooks into normalized MES tables');
