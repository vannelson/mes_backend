<?php

namespace Database\Seeders;

use App\Models\DiecutType;
use Illuminate\Database\Seeder;

class DiecutTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['document' => 'DieCutType', 'code' => 'BC', 'description' => 'BUTT CUT'],
            ['document' => 'DieCutType', 'code' => 'CB', 'description' => 'COMPUTER BUTT CUT'],
            ['document' => 'DieCutType', 'code' => 'CD', 'description' => 'COMPUTER ROUND CORNER DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'CS', 'description' => 'COMPUTER SPECIAL DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'D', 'description' => 'DIAMETER'],
            ['document' => 'DieCutType', 'code' => 'DC', 'description' => 'ROUND DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'FBC', 'description' => 'FLEXIBLE BENDOVER'],
            ['document' => 'DieCutType', 'code' => 'FD', 'description' => 'FLEXIBLE DIE CUT (DIAMETER)'],
            ['document' => 'DieCutType', 'code' => 'FDC', 'description' => 'FLEXIBLE DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'FSC', 'description' => 'FLEXIBLE (CUSTOMIZED)'],
            ['document' => 'DieCutType', 'code' => 'GF', 'description' => 'GALLUS FLEXIBLE'],
            ['document' => 'DieCutType', 'code' => 'PDC', 'description' => 'SEAGATE ROUND CORNER DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'PSC', 'description' => "SEAGATE'S SPECIAL DIE CUT"],
            ['document' => 'DieCutType', 'code' => 'S', 'description' => 'SECURITY DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'SC', 'description' => 'SPECIAL DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'SSC', 'description' => 'SOLID SPECIAL DIE CUT'],
            ['document' => 'DieCutType', 'code' => 'ZS', 'description' => 'ZEPSTICK DIE CUT'],
        ];

        foreach ($types as $type) {
            DiecutType::query()->updateOrCreate(
                ['document' => $type['document'], 'code' => $type['code']],
                ['description' => $type['description']]
            );
        }
    }
}
