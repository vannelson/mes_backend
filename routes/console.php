<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
