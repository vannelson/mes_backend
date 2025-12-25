<?php

namespace Database\Seeders;

use App\Models\Machine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class MachineSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/machines.json');

        if (! File::exists($path)) {
            $this->command?->warn('Machine seed data not found at '.$path);

            return;
        }

        $payload = json_decode(File::get($path), true) ?? [];
        if (empty($payload)) {
            $this->command?->warn('Machine seed data is empty.');

            return;
        }

        $now = now();
        $records = array_map(function ($row) use ($now) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $payload);

        Machine::truncate();
        Machine::insert($records);
    }
}
