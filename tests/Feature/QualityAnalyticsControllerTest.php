<?php

namespace Tests\Feature;

use App\Http\Controllers\QualityManagementController;
use App\Models\User;
use App\Services\QualityAnalyticsService;
use App\Services\QualityManagementService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class QualityAnalyticsControllerTest extends TestCase
{
    public function test_controller_returns_quality_analytics_payload(): void
    {
        $user = User::factory()->make(['user_type' => 'qa']);
        $analyticsService = Mockery::mock(QualityAnalyticsService::class);
        $analyticsService->shouldReceive('generate')
            ->once()
            ->andReturn([
                'run_id' => 99,
                'status' => 'completed',
                'generated_at' => now()->toIso8601String(),
                'filters' => ['characteristic' => 'PA01'],
                'summary_metrics' => ['first_pass_yield' => 99.2],
                'capability_results' => [['cpk' => 1.42]],
                'rule_summary' => ['POINT_OUTSIDE_CONTROL_LIMIT' => 1],
                'modules' => [
                    'dashboard' => ['charts' => [], 'messages' => []],
                    'aoi_spc' => ['charts' => [], 'messages' => [], 'measurement_health' => [], 'selected_characteristic' => 'PA01', 'rule_violations' => []],
                ],
            ]);
        $qualityManagementService = Mockery::mock(QualityManagementService::class);
        $controller = new QualityManagementController($qualityManagementService, $analyticsService);

        $request = Request::create('/api/v1/quality/analytics', 'GET', [
            'refresh' => 1,
            'characteristic' => 'PA01',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = $controller->analytics($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['status']);
        $this->assertSame(99, $payload['data']['run_id']);
        $this->assertSame('completed', $payload['data']['status']);
        $this->assertSame('PA01', $payload['data']['filters']['characteristic']);
    }
}
