<?php

namespace App\Services;

use App\Models\CalibrationMaster;
use Illuminate\Support\Facades\Http;

class CalibrationSlackAlertService
{
    private const STATUS_CONFIG = [
        'out_for_calibration' => ['label' => 'Out for Calibration', 'color' => '#1670c9', 'emoji' => '🔵'],
        'cert_received'       => ['label' => 'Certificate Received', 'color' => '#0d9488', 'emoji' => '✅'],
        'spare'               => ['label' => 'Spare',                'color' => '#7c3aed', 'emoji' => '🟣'],
        'calibrated'          => ['label' => 'Calibrated',           'color' => '#2f9e6e', 'emoji' => '🟢'],
        null                  => ['label' => 'Active (cleared)',      'color' => '#5b6470', 'emoji' => '⚪'],
    ];

    public static function statusChanged(CalibrationMaster $record, ?string $newStatus, string $performedBy): void
    {
        $webhook = config('services.slack.calibration_webhook');
        if (! $webhook) {
            return;
        }

        $cfg      = self::STATUS_CONFIG[$newStatus] ?? self::STATUS_CONFIG[null];
        $name     = $record->name_type ?: 'Unknown Instrument';
        $sn       = $record->identification_number ?: '—';
        $location = self::cleanLocation($record->owner_location);
        $date     = now()->timezone('Asia/Manila')->format('j M Y, g:i A');

        $text = "{$cfg['emoji']}  *{$name}*\n"
              . "S/N  {$sn}\n"
              . "Status  *{$cfg['label']}*\n"
              . ($location ? "Location  {$location}\n" : '')
              . "Updated by  {$performedBy}\n"
              . "_{$date}_";

        $payload = [
            'text'        => "{$cfg['emoji']} {$name} — status changed to {$cfg['label']}",
            'attachments' => [[
                'color'  => $cfg['color'],
                'blocks' => [[
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $text],
                ]],
            ]],
        ];

        Http::timeout(5)->post($webhook, $payload);
    }

    public static function calibrationRecorded(CalibrationMaster $record, string $performedBy, ?string $calDate): void
    {
        $webhook = config('services.slack.calibration_webhook');
        if (! $webhook) {
            return;
        }

        $cfg      = self::STATUS_CONFIG['calibrated'];
        $name     = $record->name_type ?: 'Unknown Instrument';
        $sn       = $record->identification_number ?: '—';
        $location = self::cleanLocation($record->owner_location);
        $nextDue  = $record->next_calibration_date?->format('j M Y') ?? '—';
        $date     = now()->timezone('Asia/Manila')->format('j M Y, g:i A');

        $text = "{$cfg['emoji']}  *{$name}*\n"
              . "S/N  {$sn}\n"
              . "Status  *Calibrated*\n"
              . ($calDate ? "Calibrated on  {$calDate}\n" : '')
              . "Next due  {$nextDue}\n"
              . ($location ? "Location  {$location}\n" : '')
              . "Recorded by  {$performedBy}\n"
              . "_{$date}_";

        $payload = [
            'text'        => "{$cfg['emoji']} {$name} — calibration recorded",
            'attachments' => [[
                'color'  => $cfg['color'],
                'blocks' => [[
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $text],
                ]],
            ]],
        ];

        Http::timeout(5)->post($webhook, $payload);
    }

    private static function cleanLocation(?string $raw): string
    {
        if (! $raw) {
            return '';
        }

        return trim(preg_replace('/\s*[\r\n]+\s*/', ', ', $raw));
    }

    public static function resolveUserName(): string
    {
        $user = auth()->user();
        if (! $user) {
            return 'System';
        }

        $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));

        return $name ?: ($user->email ?? 'Unknown User');
    }
}
