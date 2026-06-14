<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\QualityAnalyticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateQualityAnalyticsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $filters = [],
        public ?int $requestedByUserId = null
    ) {
    }

    public function handle(QualityAnalyticsService $qualityAnalyticsService): void
    {
        $user = $this->requestedByUserId ? User::query()->find($this->requestedByUserId) : null;
        $qualityAnalyticsService->generate($this->filters, $user, true);
    }
}
