<?php

namespace App\Console\Commands;

use App\Models\CalibrationMaster;
use App\Support\CalibrationSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CalibrationSlackNotify extends Command
{
    protected $signature   = 'calibration:slack-notify';
    protected $description = 'Send daily Slack alert for overdue and due-soon calibration instruments';

    public function handle(): int
    {
        $webhook = config('services.slack.calibration_webhook');

        if (! $webhook) {
            $this->warn('SLACK_CALIBRATION_WEBHOOK not set — skipping.');
            return 0;
        }

        $today   = Carbon::today();
        $overdue = [];
        $dueSoon = [];

        CalibrationMaster::whereNotNull('name_type')
            ->where('name_type', '!=', '')
            ->get()
            ->each(function (CalibrationMaster $item) use ($today, &$overdue, &$dueSoon) {
                // Honour manual status overrides — same logic as frontend effectiveStatus()
                $calStatus = $item->metadata['cal_status'] ?? null;
                if (in_array($calStatus, ['out_for_calibration', 'cert_received', 'spare'], true)) {
                    return; // actively managed — skip
                }

                if (! $item->next_calibration_date) {
                    return; // unscheduled — skip
                }

                $state = CalibrationSchedule::dueState($item->next_calibration_date, $today);

                $row = [
                    'name'     => $item->name_type,
                    'sn'       => $item->identification_number ?: '—',
                    'location' => $item->owner_location ?: '—',
                ];

                if ($state === 'overdue') {
                    $row['days'] = abs((int) $today->diffInDays($item->next_calibration_date, false));
                    $overdue[]   = $row;
                } elseif ($state === 'due_today') {
                    $row['days'] = 0;
                    $dueSoon[]   = $row;
                } elseif ($state === 'due_soon') {
                    $row['days'] = (int) $today->diffInDays($item->next_calibration_date, false);
                    $dueSoon[]   = $row;
                }
            });

        if (empty($overdue) && empty($dueSoon)) {
            $this->info('All instruments are on schedule — no alert sent.');
            return 0;
        }

        // Most-overdue first; due-soon soonest first
        usort($overdue, fn ($a, $b) => $b['days'] <=> $a['days']);
        usort($dueSoon, fn ($a, $b) => $a['days'] <=> $b['days']);

        $payload  = $this->buildPayload($today, $overdue, $dueSoon);
        $response = Http::timeout(10)->post($webhook, $payload);

        if ($response->successful()) {
            $this->info(sprintf(
                'Slack alert sent — %d overdue, %d due soon.',
                count($overdue),
                count($dueSoon)
            ));
            return 0;
        }

        $this->error("Slack webhook failed [{$response->status()}]: {$response->body()}");
        return 1;
    }

    private function buildPayload(Carbon $today, array $overdue, array $dueSoon): array
    {
        $dateLabel   = $today->format('j M Y');
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $calUrl      = rtrim($frontendUrl, '/') . '/quality/calibration-master';
        $total       = count($overdue) + count($dueSoon);

        $attachments = [];

        // ── Summary header ───────────────────────────────────────────────────
        $parts = [];
        if (! empty($overdue)) $parts[] = count($overdue) . ' overdue';
        if (! empty($dueSoon)) $parts[] = count($dueSoon) . ' due soon';

        $attachments[] = [
            'color'  => '#16181d',
            'blocks' => [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*🔬 Calibration Alert — {$dateLabel}*\n" . implode('  ·  ', $parts),
                ],
            ]],
        ];

        // ── One card per overdue instrument ──────────────────────────────────
        $shown   = 0;
        $maxShow = 20; // Slack gets unwieldy past ~20 cards
        foreach ($overdue as $i) {
            if ($shown >= $maxShow) break;
            $loc = $this->cleanLocation($i['location']);
            $attachments[] = [
                'color'  => '#c0322b',
                'blocks' => [[
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "🔴  *{$i['name']}*\nS/N  {$i['sn']}\nStatus  *Over Due*  ·  {$i['days']} days past due\n{$loc}",
                    ],
                ]],
            ];
            $shown++;
        }

        if (count($overdue) > $maxShow) {
            $remaining     = count($overdue) - $maxShow;
            $attachments[] = [
                'color'  => '#c0322b',
                'blocks' => [[
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "_…and {$remaining} more overdue instruments_"],
                ]],
            ];
        }

        // ── One card per due-soon instrument ─────────────────────────────────
        foreach ($dueSoon as $i) {
            $loc  = $this->cleanLocation($i['location']);
            $when = $i['days'] === 0 ? 'due *Today*' : "due in {$i['days']} days";
            $attachments[] = [
                'color'  => '#d4880c',
                'blocks' => [[
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "🟡  *{$i['name']}*\nS/N  {$i['sn']}\nStatus  *Due Soon*  ·  {$when}\n{$loc}",
                    ],
                ]],
            ];
        }

        // ── Footer ───────────────────────────────────────────────────────────
        $noun          = $total === 1 ? 'instrument needs' : 'instruments need';
        $attachments[] = [
            'color'  => '#e7e9ed',
            'blocks' => [
                [
                    'type'     => 'actions',
                    'elements' => [[
                        'type'  => 'button',
                        'text'  => ['type' => 'plain_text', 'text' => 'Open Calibration Master', 'emoji' => true],
                        'url'   => $calUrl,
                        'style' => 'primary',
                    ]],
                ],
                [
                    'type'     => 'context',
                    'elements' => [['type' => 'mrkdwn', 'text' => "MES · Quality  ·  {$total} {$noun} attention"]],
                ],
            ],
        ];

        return [
            'text'        => "Calibration Alert — {$dateLabel}: " . count($overdue) . ' overdue, ' . count($dueSoon) . ' due soon.',
            'attachments' => $attachments,
        ];
    }

    private function cleanLocation(?string $raw): string
    {
        if (! $raw) return '';
        // Flatten multi-line Excel-imported locations into a single line
        return trim(preg_replace('/\s*[\r\n]+\s*/', ', ', $raw));
    }
}
