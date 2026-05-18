<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkOrderMetadataFixExamineTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_user_can_examine_and_preview_normalized_work_order_metadata(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create([
            'user_type' => 'admin',
        ]);

        $workOrder = $this->createCorruptedWorkOrder();

        AuditLog::query()->forceCreate([
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'route_key' => 'R02',
            'action' => 'validation',
            'summary' => 'Completed validation for AOI on work order ' . $workOrder->work_order_no,
            'details' => [
                'meta' => [
                    'step_name' => 'AOI',
                    'step_key' => 'AOI',
                ],
            ],
            'created_at' => Carbon::parse('2026-05-18 09:14:09', 'Asia/Singapore'),
            'updated_at' => Carbon::parse('2026-05-18 09:14:09', 'Asia/Singapore'),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/labs/work-order-metadata/examine', [
            'work_order_id' => $workOrder->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.work_order.id', $workOrder->id)
            ->assertJsonPath('data.suggested_payload.status', 'In Progress')
            ->assertJsonPath('data.suggested_payload.production_date_completed', null)
            ->assertJsonPath('data.suggested_payload.production_qty_completed', null)
            ->assertJsonPath('data.suggested_payload.completed_at', null)
            ->assertJsonPath('data.suggested_payload.metadata.state.status', 'In Progress')
            ->assertJsonPath('data.suggested_payload.metadata.state.currentStep', 3)
            ->assertJsonPath('data.suggested_payload.metadata.routes.1.params.slitting_press_k15yecdr', '12000')
            ->assertJsonPath('data.suggested_payload.metadata.routes.2.route_key', 'aoi')
            ->assertJsonPath('data.suggested_payload.metadata.routes.2.route', 'aoi')
            ->assertJsonPath('data.suggested_payload.metadata.routes.2.metadata.route_key', 'aoi')
            ->assertJsonPath('data.suggested_payload.metadata.routes.2.status', 'completed')
            ->assertJsonPath('data.suggested_payload.metadata.routes.2.completed_at', '2026-05-18T01:14:09+00:00')
            ->assertJsonPath('data.suggested_payload.metadata.assignments.routes.2.route_key', 'aoi')
            ->assertJsonPath('data.suggested_payload.metadata.assignments.routes.2.route', 'aoi');

        $this->assertCount(
            4,
            $response->json('data.suggested_payload.metadata.assignments.routes')
        );
        $this->assertContains(
            'metadata.routes.2.route_key',
            $response->json('data.changed_paths')
        );
        $this->assertContains(
            'metadata.assignments.routes',
            $response->json('data.changed_paths')
        );
    }

    public function test_non_privileged_user_cannot_examine_work_order_metadata(): void
    {
        $operator = User::factory()->withoutTwoFactor()->create([
            'user_type' => 'operator',
        ]);

        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/v1/labs/work-order-metadata/examine', [
            'work_order_id' => 55374,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Forbidden.');
    }

    protected function createCorruptedWorkOrder(): WorkOrder
    {
        return WorkOrder::query()->forceCreate([
            'work_order_no' => 'W0707558',
            'date' => Carbon::parse('2026-04-20'),
            'posted_date' => Carbon::parse('2026-04-20'),
            'document_date' => Carbon::parse('2026-04-20'),
            'due_date' => Carbon::parse('2026-04-30'),
            'customer_part_number' => 'L001-772785-000003ZL',
            'quantity_to_produce' => '18000',
            'production_date_completed' => Carbon::parse('2026-05-04'),
            'production_qty_completed' => '18000',
            'status' => 'Completed',
            'is_released' => true,
            'completed_at' => Carbon::parse('2026-05-18T01:13:59Z'),
            'metadata' => [
                'routes' => [
                    [
                        'route_key' => 'R01',
                        'order_seq' => 1,
                        'route' => 'R01',
                        'name' => 'LP',
                        'status' => 'completed',
                        'completed_at' => '2026-05-06T06:26:35.633Z',
                        'params' => [
                            'lp_press_iwdlxded' => '3000',
                        ],
                        'parameters' => [
                            [
                                'name' => 'lp_press_iwdlxded',
                                'current_value' => '3000',
                            ],
                        ],
                        'metadata' => [
                            'route_key' => 'R01',
                            'order_seq' => 1,
                            'timeTracker' => [
                                'entries' => [
                                    [
                                        'operator_id' => 28,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'route_key' => 'R02',
                        'order_seq' => 2,
                        'route' => 'R02',
                        'name' => 'SLITTING',
                        'status' => 'completed',
                        'completed_at' => '2026-05-18T01:13:57.650Z',
                        'params' => [
                            'aoi_press_ohh3nwtx' => '72000',
                            'aoi_date_vuw64mez' => '2026-02-04',
                        ],
                        'parameters' => [
                            [
                                'name' => 'slitting_press_k15yecdr',
                                'current_value' => '12000',
                            ],
                            [
                                'name' => 'slitting_date_w0ekdz03',
                                'current_value' => '2026-02-27',
                            ],
                        ],
                        'metadata' => [
                            'route_key' => 'R02',
                            'order_seq' => 2,
                        ],
                    ],
                    [
                        'route_key' => 'R02',
                        'order_seq' => 3,
                        'route' => 'R02',
                        'name' => 'AOI',
                        'status' => 'pending',
                        'completed_at' => null,
                        'params' => [
                            'aoi_press_ohh3nwtx' => '72000',
                        ],
                        'parameters' => [
                            [
                                'name' => 'aoi_press_ohh3nwtx',
                                'current_value' => '72000',
                            ],
                        ],
                        'metadata' => [
                            'route_key' => 'R02',
                            'order_seq' => 3,
                        ],
                    ],
                    [
                        'route_key' => 'R03',
                        'order_seq' => 4,
                        'route' => 'R03',
                        'name' => 'INSPECTION',
                        'status' => 'pending',
                        'completed_at' => null,
                        'metadata' => [
                            'route_key' => 'R03',
                            'order_seq' => 4,
                        ],
                    ],
                ],
                'steps' => [
                    'LP',
                    'SLITTING',
                    'AOI',
                    'INSPECTION',
                ],
                'state' => [
                    'status' => 'Completed',
                    'currentStep' => 2,
                    'assignees' => ['28', '16'],
                    'workOrderNo' => 'W0707558',
                ],
                'assignments' => [
                    'routes' => [
                        [
                            'route_key' => 'R01',
                            'order_seq' => 1,
                            'route' => 'R01',
                            'name' => 'LP',
                            'operators' => [
                                ['id' => '28', 'qty' => null],
                            ],
                        ],
                        [
                            'route_key' => 'R02',
                            'order_seq' => 2,
                            'route' => 'R02',
                            'name' => 'SLITTING',
                            'operators' => [
                                ['id' => '16', 'qty' => null],
                            ],
                        ],
                        [
                            'route_key' => 'R02',
                            'order_seq' => 3,
                            'route' => 'R02',
                            'name' => 'AOI',
                            'operators' => [
                                ['id' => '16', 'qty' => null],
                            ],
                        ],
                        [
                            'route_key' => 'R02',
                            'order_seq' => 3,
                            'route' => 'aoi',
                            'name' => 'AOI',
                            'operators' => [],
                        ],
                        [
                            'route_key' => 'R02',
                            'order_seq' => 3,
                            'route' => 'R02',
                            'name' => 'AOI',
                            'operators' => [
                                ['id' => '16', 'qty' => null],
                            ],
                        ],
                    ],
                ],
                'archived_routes' => [],
            ],
        ]);
    }
}
