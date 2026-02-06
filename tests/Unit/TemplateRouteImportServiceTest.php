<?php

namespace Tests\Unit;

use App\Services\TemplateRouteImportService;
use ReflectionMethod;
use Tests\TestCase;

class TemplateRouteImportServiceTest extends TestCase
{
    public function test_normalize_machine_type_variants(): void
    {
        $service = new TemplateRouteImportService();
        $method = new ReflectionMethod($service, 'normalizeMachineType');
        $method->setAccessible(true);

        $this->assertSame('DIE-CUT (D)', $method->invoke($service, 'Die Cut (D)'));
        $this->assertSame('DIE-CUT (L)', $method->invoke($service, 'Die-Cut (L)'));
        $this->assertSame('DIE-CUT', $method->invoke($service, 'Die Cut'));
        $this->assertSame('FLEXO', $method->invoke($service, 'Flexo'));
        $this->assertNull($method->invoke($service, 'Unknown'));
    }

    public function test_build_templates_dedupes_machine_types_and_orders(): void
    {
        $service = new TemplateRouteImportService();
        $rows = [
            [
                'customer_part_number' => 'PART-A',
                'work_order_line_no' => 10000,
                'wo_journal_line_no' => 200,
                'machine_type' => 'FLEXO',
                'machine_code' => 'F1',
                'machine_name' => 'Flexo A',
                'process_no' => 2,
                'posted' => true,
            ],
            [
                'customer_part_number' => 'PART-A',
                'work_order_line_no' => 10000,
                'wo_journal_line_no' => 100,
                'machine_type' => 'DIGITAL',
                'machine_code' => 'D1',
                'machine_name' => 'Digital One',
                'process_no' => 1,
                'posted' => true,
            ],
            [
                'customer_part_number' => 'PART-A',
                'work_order_line_no' => 10000,
                'wo_journal_line_no' => 300,
                'machine_type' => 'FLEXO',
                'machine_code' => 'F2',
                'machine_name' => 'Flexo B',
                'process_no' => 3,
                'posted' => true,
            ],
            [
                'customer_part_number' => 'PART-B',
                'work_order_line_no' => 10000,
                'wo_journal_line_no' => 100,
                'machine_type' => 'DIGITAL',
                'machine_code' => 'D1',
                'machine_name' => 'Digital One',
                'process_no' => 1,
                'posted' => true,
            ],
            [
                'customer_part_number' => 'PART-B',
                'work_order_line_no' => 10000,
                'wo_journal_line_no' => 200,
                'machine_type' => 'FLEXO',
                'machine_code' => 'F9',
                'machine_name' => 'Flexo B',
                'process_no' => 2,
                'posted' => true,
            ],
        ];

        $result = $service->buildTemplatesFromRows($rows);
        $this->assertCount(1, $result['templates']);
        $template = $result['templates'][0];

        $this->assertSame('DIGITAL->FLEXO', $template['sequence']);
        $this->assertSame('DIGITAL[Digital One]->FLEXO[Flexo A]', $template['route_sequence_with_machines']);
        $this->assertSame(2, $template['step_count']);
        $this->assertSame(2, $template['parts_count']);
        $this->assertCount(2, $template['lines'][0]['steps']);
    }
}
