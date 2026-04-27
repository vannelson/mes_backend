<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Repositories\WorkOrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkOrderMetadataUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_update_persists_metadata_as_json_cast_array(): void
    {
        $workOrder = WorkOrder::query()->forceCreate([
            'work_order_no' => 'WO-META-001',
            'date' => Carbon::parse('2026-04-27'),
            'posted_date' => Carbon::parse('2026-04-27'),
            'document_date' => Carbon::parse('2026-04-27'),
            'due_date' => Carbon::parse('2026-04-30'),
            'metadata' => [],
        ]);

        $metadata = [
            'routes' => [
                [
                    'workOrderLineNo' => 10000,
                    'routes' => [
                        [
                            'route_key' => 'inspection',
                            'order_seq' => 1,
                            'route' => 'Inspection',
                            'name' => 'Inspection',
                            'machine' => [
                                'id' => 79,
                                'machine_no' => 'QC05',
                                'machine_name' => 'Label Counter 5',
                            ],
                            'metadata' => [
                                'machine' => [
                                    'id' => 79,
                                    'machine_no' => 'QC05',
                                    'machine_name' => 'Label Counter 5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'state' => [
                'currentStep' => 0,
                'status' => 'In Progress',
            ],
        ];

        $updated = app(WorkOrderRepository::class)->update($workOrder->id, [
            'metadata' => $metadata,
        ]);

        $this->assertSame(1, $updated);

        $workOrder->refresh();

        $this->assertIsArray($workOrder->metadata);
        $this->assertSame(
            'QC05',
            data_get($workOrder->metadata, 'routes.0.routes.0.machine.machine_no')
        );
        $this->assertSame(
            'Label Counter 5',
            data_get($workOrder->metadata, 'routes.0.routes.0.machine.machine_name')
        );
    }
}
