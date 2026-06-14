<?php

namespace Tests\Unit;

use App\Support\QualityAnalyticsNativeEngine;
use Tests\TestCase;

class QualityAnalyticsNativeEngineTest extends TestCase
{
    public function test_it_generates_native_quality_analytics_without_external_runtime(): void
    {
        $engine = new QualityAnalyticsNativeEngine();

        $result = $engine->generate([
            'filters' => ['characteristic' => 'PA01'],
            'summary_metrics' => ['first_pass_yield' => 98.5],
            'dashboard' => [
                'issue_trends' => [
                    ['period' => '2026-06', 'customer_count' => 2, 'supplier_count' => 1, 'source_links' => []],
                ],
                'defect_pareto' => [
                    ['label' => 'Solder void', 'count' => 3, 'source_links' => []],
                ],
                'machine_rankings' => [],
                'operator_rankings' => [],
                'calibration_compliance' => [],
                'vpd_claim_trends' => [],
                'supplier_claim_pareto' => [],
                'capa_aging' => [],
                'follow_up_validation' => [],
                'aoi_pass_fail' => [],
            ],
            'aoi_spc' => [
                'selected_characteristic' => 'PA01',
                'points' => [
                    ['detail_id' => 1, 'header_id' => 10, 'measurement_time' => '2026-06-14T08:00:00+08:00', 'value' => 1.01, 'lsl' => 0.95, 'usl' => 1.05, 'nominal' => 1.0, 'result_status' => 'OK', 'characteristic_code' => 'PA01', 'subgroup_key' => 'LOT-1', 'is_out_of_spec' => false],
                    ['detail_id' => 2, 'header_id' => 11, 'measurement_time' => '2026-06-14T08:10:00+08:00', 'value' => 1.02, 'lsl' => 0.95, 'usl' => 1.05, 'nominal' => 1.0, 'result_status' => 'OK', 'characteristic_code' => 'PA01', 'subgroup_key' => 'LOT-1', 'is_out_of_spec' => false],
                    ['detail_id' => 3, 'header_id' => 12, 'measurement_time' => '2026-06-14T08:20:00+08:00', 'value' => 0.99, 'lsl' => 0.95, 'usl' => 1.05, 'nominal' => 1.0, 'result_status' => 'NG', 'characteristic_code' => 'PA01', 'subgroup_key' => 'LOT-2', 'is_out_of_spec' => false],
                ],
            ],
        ]);

        $this->assertSame('native-php-spc-engine', $result['engine_name']);
        $this->assertSame('PA01', $result['metadata']['selected_characteristic']);
        $this->assertNotEmpty($result['modules']['dashboard']['charts']);
        $this->assertNotEmpty($result['modules']['aoi_spc']['charts']);
    }
}
