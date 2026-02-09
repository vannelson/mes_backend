<?php

namespace Database\Seeders;

use App\Models\Machine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class MachineImageSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/machines_summary.json');

        if (! File::exists($path)) {
            $this->command?->warn('Machine image seed data not found at '.$path);

            return;
        }

        $payload = json_decode(File::get($path), true) ?? [];
        if (empty($payload)) {
            $this->command?->warn('Machine image seed data is empty.');

            return;
        }

        $records = [];
        $machineNos = [];

        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['machine_code'] ?? ''));
            $machineName = isset($row['machine_name']) ? trim((string) $row['machine_name']) : null;
            $imageFile = isset($row['image_file']) ? (string) $row['image_file'] : '';
            $imageFilename = $imageFile !== '' ? basename($imageFile) : null;
            $urlpath = $imageFilename ? '/images/machines/'.$imageFilename : null;
            $numbers = $this->extractMachineNumbers($code);

            if (! empty($numbers)) {
                $machineNos = array_merge($machineNos, $numbers);
            }

            $records[] = [
                'code' => $code,
                'machine_name' => $machineName ?: null,
                'image_filename' => $imageFilename,
                'urlpath' => $urlpath,
                'machine_nos' => $numbers,
            ];
        }

        $machineNos = array_values(array_unique(array_filter($machineNos, fn ($value) => $value !== '')));
        $existing = $machineNos
            ? Machine::query()->whereIn('machine_no', $machineNos)->get()->keyBy('machine_no')
            : collect();

        foreach ($records as $record) {
            $metadata = $this->buildMetadata($record);

            if (! empty($record['machine_nos'])) {
                foreach ($record['machine_nos'] as $machineNo) {
                    $machine = $existing->get($machineNo);

                    if ($machine) {
                        $machine->metadata = $this->mergeMetadata($machine->metadata, $metadata);
                        $machine->save();
                        continue;
                    }

                    $machine = Machine::create([
                        'machine_no' => $machineNo,
                        'machine_name' => $record['machine_name'] ?: ($record['code'] ?: null),
                        'metadata' => $metadata,
                    ]);

                    $existing->put($machineNo, $machine);
                }

                continue;
            }

            if ($record['code'] === '') {
                continue;
            }

            if ($existing->has($record['code'])) {
                $machine = $existing->get($record['code']);
                $machine->metadata = $this->mergeMetadata($machine->metadata, $metadata);
                $machine->save();
                continue;
            }

            $machine = Machine::create([
                'machine_no' => $record['code'],
                'machine_name' => $record['machine_name'] ?: $record['code'],
                'metadata' => $metadata,
            ]);

            $existing->put($record['code'], $machine);
        }
    }

    private function extractMachineNumbers(string $code): array
    {
        if ($code === '') {
            return [];
        }

        if (! preg_match_all('/\bM(\d+)\b/i', $code, $matches)) {
            return [];
        }

        $numbers = [];
        foreach ($matches[1] as $match) {
            $normalized = ltrim($match, '0');
            $numbers[] = $normalized === '' ? '0' : $normalized;
        }

        return array_values(array_unique($numbers));
    }

    private function buildMetadata(array $record): array
    {
        $metadata = [];

        if (! empty($record['code'])) {
            $metadata['machine_code'] = $record['code'];
        }

        if (! empty($record['machine_name'])) {
            $metadata['machine_name'] = $record['machine_name'];
        }

        if (! empty($record['image_filename'])) {
            $metadata['image_filename'] = $record['image_filename'];
        }

        if (! empty($record['urlpath'])) {
            $metadata['urlpath'] = $record['urlpath'];
        }

        return $metadata;
    }

    private function mergeMetadata(?array $current, array $new): array
    {
        $existing = is_array($current) ? $current : [];

        foreach ($new as $key => $value) {
            $existing[$key] = $value;
        }

        return $existing;
    }
}
