<?php

namespace Database\Seeders;

use App\Models\Machine;
use Illuminate\Database\Seeder;

class MachineTypeUpdateSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['machine_no' => '71', 'machine_name' => 'POLLY', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '86', 'machine_name' => 'Brotech DF330', 'machine_type' => 'Flexo'],
            ['machine_no' => '93', 'machine_name' => 'HP Indigo 6900', 'machine_type' => 'Digital'],
            ['machine_no' => '95', 'machine_name' => 'HDS 8', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '79', 'machine_name' => 'HP Indigo 6800', 'machine_type' => 'Digital'],
            ['machine_no' => '112', 'machine_name' => 'Hadesheng', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '94', 'machine_name' => 'Taiyo', 'machine_type' => 'LP'],
            ['machine_no' => '82', 'machine_name' => 'POLLY', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '111', 'machine_name' => 'Brotech', 'machine_type' => 'Flexo'],
            ['machine_no' => '110', 'machine_name' => 'Brotech', 'machine_type' => 'Flexo'],
            ['machine_no' => '65', 'machine_name' => 'Sanki SDR300', 'machine_type' => 'LP'],
            ['machine_no' => '66', 'machine_name' => 'Fujishiko', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '62', 'machine_name' => 'Fujishiko', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '88', 'machine_name' => 'OKI', 'machine_type' => 'Digital'],
            ['machine_no' => '77', 'machine_name' => 'Brotech DF330', 'machine_type' => 'Flexo'],
            ['machine_no' => '85', 'machine_name' => 'Luster AOI', 'machine_type' => 'AOI'],
            ['machine_no' => '46', 'machine_name' => 'Sanki 300 Intermittent', 'machine_type' => 'LP'],
            ['machine_no' => '73', 'machine_name' => 'Brotech DF330', 'machine_type' => 'Flexo'],
            ['machine_no' => '96', 'machine_name' => 'Luster AOI', 'machine_type' => 'AOI'],
            ['machine_no' => '76', 'machine_name' => 'HDS 3', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '103', 'machine_name' => 'HDS', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '32', 'machine_name' => 'Lintec Intermittent', 'machine_type' => 'LP'],
            ['machine_no' => '91', 'machine_name' => 'Luster AOI', 'machine_type' => 'AOI'],
            ['machine_no' => '31', 'machine_name' => 'Sanki SKP250 Full Rotary', 'machine_type' => 'LP'],
            ['machine_no' => '78', 'machine_name' => 'Baby Sanki', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '29', 'machine_name' => 'Sanki SKP250 Full Rotary', 'machine_type' => 'LP'],
            ['machine_no' => '109', 'machine_name' => 'Brotech', 'machine_type' => 'Flexo'],
            ['machine_no' => '60', 'machine_name' => 'Fujishiko UDP-5000', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '59', 'machine_name' => 'SANKI SD-300 Intermittent', 'machine_type' => 'LP'],
            ['machine_no' => '75', 'machine_name' => 'HDS 3', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '101', 'machine_name' => 'Luster AOI', 'machine_type' => 'AOI'],
            ['machine_no' => '26', 'machine_name' => 'Sanki SKP250 Full Rotary', 'machine_type' => 'LP'],
            ['machine_no' => '90', 'machine_name' => 'HDS', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '100', 'machine_name' => 'Vorey Digital Cut', 'machine_type' => 'Die-Cut (D)'],
            ['machine_no' => '60', 'machine_name' => 'Fujishiko UDP-5000', 'machine_type' => 'LP'],
            ['machine_no' => '63', 'machine_name' => 'SANKI', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '68', 'machine_name' => 'Sanki', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '70', 'machine_name' => 'SANKI', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '108', 'machine_name' => 'Brotech', 'machine_type' => 'Flexo'],
            ['machine_no' => 'LUSTER B', 'machine_name' => 'Luster AOI', 'machine_type' => 'AOI'],
            ['machine_no' => '87', 'machine_name' => 'SYSCO', 'machine_type' => 'Die-Cut'],
            ['machine_no' => '92', 'machine_name' => 'Polly Digital Cut', 'machine_type' => 'Die-Cut (D)'],
            ['machine_no' => '115', 'machine_name' => 'AOI Slitter (iPolly Intelligence)', 'machine_type' => 'AOI'],
            ['machine_no' => '113', 'machine_name' => 'Brotech', 'machine_type' => 'Flexo'],
            ['machine_no' => '72', 'machine_name' => 'Xeikon 3500', 'machine_type' => 'Digital'],
            ['machine_no' => '105', 'machine_name' => 'Laser', 'machine_type' => 'Die-Cut (L)'],
            ['machine_no' => '67', 'machine_name' => 'Fujishiko', 'machine_type' => 'Die-Cut'],
            ['machine_no' => 'ZC53', 'machine_name' => 'Zebra', 'machine_type' => 'Digital'],
            ['machine_no' => '117', 'machine_name' => 'HDS', 'machine_type' => 'Die-Cut'],
        ];

        $updates = [];
        foreach ($rows as $row) {
            $machineNo = trim((string) ($row['machine_no'] ?? ''));
            if ($machineNo === '') {
                continue;
            }

            $updates[$machineNo] = [
                'machine_name' => isset($row['machine_name']) ? trim((string) $row['machine_name']) : null,
                'machine_type' => isset($row['machine_type']) ? trim((string) $row['machine_type']) : null,
            ];
        }

        foreach ($updates as $machineNo => $payload) {
            $clean = array_filter(
                $payload,
                static fn($value) => $value !== null && $value !== ''
            );

            if (empty($clean)) {
                continue;
            }

            Machine::updateOrCreate(
                ['machine_no' => $machineNo],
                $clean
            );
        }
    }
}
