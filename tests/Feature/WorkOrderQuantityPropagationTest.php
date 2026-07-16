<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkOrderQuantityPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_forces_route_target_qty_to_match_work_order_quantity(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create([
            'user_type' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $workOrder = $this->createWorkOrderWithMismatchedRouteQty();

        $response = $this->putJson("/api/work-orders/{$workOrder->id}", [
            'metadata' => $workOrder->metadata,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.metadata.state.qty', 1000);

        $workOrder->refresh();

        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'state.qty'));
        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'routes.0.qty'));
        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'routes.0.targetPrintedQty'));
        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'routes.0.metadata.targetPrintedQty'));
        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'routes.0.metadata.pressPlan.targetPrintedQty'));
        $this->assertEquals(1000.0, data_get($workOrder->metadata, 'routes.0.metadata.timeTracker.entries.0.target_printed_qty'));
    }

    public function test_time_tracker_progress_forces_entry_target_qty_to_match_work_order_quantity(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create([
            'user_type' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $workOrder = $this->createWorkOrderWithMismatchedRouteQty();

        $response = $this->postJson("/api/work-orders/{$workOrder->id}/time-tracker", [
            'route_key' => 'printing',
            'operator_id' => $admin->id,
            'action' => 'progress',
            'printed_qty' => 120,
            'total_printed_qty' => 120,
            'target_printed_qty' => 400,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.entry.target_printed_qty', 1000);

        $workOrder->refresh();

        $entries = data_get($workOrder->metadata, 'routes.0.metadata.timeTracker.entries', []);
        $this->assertNotEmpty($entries);
        $this->assertEquals(1000.0, data_get(end($entries), 'target_printed_qty'));
    }

    protected function createWorkOrderWithMismatchedRouteQty(): WorkOrder
    {
        return WorkOrder::query()->forceCreate([
            'work_order_no' => 'WO-QTY-001',
            'date' => Carbon::parse('2026-07-15'),
            'posted_date' => Carbon::parse('2026-07-15'),
            'document_date' => Carbon::parse('2026-07-15'),
            'due_date' => Carbon::parse('2026-07-20'),
            'quantity_to_produce' => '1000',
            'metadata' => [
                'routes' => [
                    [
                        'route_key' => 'printing',
                        'order_seq' => 1,
                        'route' => 'Printing',
                        'name' => 'Printing',
                        'qty' => 400,
                        'targetPrintedQty' => 400,
                        'metadata' => [
                            'route_key' => 'printing',
                            'order_seq' => 1,
                            'qty' => 400,
                            'targetPrintedQty' => 400,
                            'pressPlan' => [
                                'targetPrintedQty' => 400,
                            ],
                            'timeTracker' => [
                                'entries' => [
                                    [
                                        'action' => 'progress',
                                        'operator_id' => 1,
                                        'target_printed_qty' => 400,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'state' => [
                    'status' => 'In Progress',
                    'currentStep' => 0,
                    'qty' => 400,
                ],
            ],
        ]);
    }
}
