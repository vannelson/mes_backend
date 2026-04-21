<?php

namespace Database\Seeders;

use App\Models\Diecut;
use App\Models\DiecutProfile;
use App\Models\DiecutProfileAlias;
use App\Services\DiecutIntelligenceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DiecutIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('diecuts') || !Schema::hasTable('diecut_profiles')) {
            return;
        }

        $normalizer = app(DiecutIntelligenceService::class);

        Diecut::query()
            ->whereNotNull('diecut_no')
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($normalizer) {
                foreach ($rows as $row) {
                    $code = trim((string) $row->diecut_no);
                    if ($code === '') {
                        continue;
                    }

                    $profile = DiecutProfile::query()->updateOrCreate(
                        ['normalized_code' => $normalizer->normalizeCode($code)],
                        [
                            'profile_code' => $code,
                            'base_normalized_code' => $normalizer->normalizeBaseCode($code),
                            'diecut_type' => $row->diecut_type,
                            'width_mm' => $normalizer->toFloat($row->width),
                            'height_mm' => $normalizer->toFloat($row->length),
                            'no_of_ups' => $normalizer->toFloat($row->no_of_ups),
                            'rev' => $row->rev,
                            'source_sheet' => $row->sheet,
                            'source_batch' => $row->batch_number,
                            'metadata' => [
                                'legacy_diecut_id' => $row->id,
                                'radius' => $row->radius,
                                'perforate' => $row->perforate,
                                'int_ud' => $row->int_ud,
                            ],
                        ]
                    );

                    DiecutProfileAlias::query()->updateOrCreate(
                        ['normalized_alias' => $normalizer->normalizeCode($code)],
                        [
                            'diecut_profile_id' => $profile->id,
                            'alias_code' => $code,
                            'base_normalized_alias' => $normalizer->normalizeBaseCode($code),
                            'alias_type' => 'legacy_diecuts',
                            'confidence_score' => 1.0,
                        ]
                    );
                }
            });
    }
}
