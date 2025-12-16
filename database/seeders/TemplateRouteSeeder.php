<?php

namespace Database\Seeders;

use App\Models\TemplateRoute;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TemplateRouteSeeder extends Seeder
{
    public function run(): void
    {
        $supervisors = User::where('user_type', 'supervisor')->pluck('id')->all();

        if (empty($supervisors)) {
            $this->command?->warn('No supervisors found. Skipping TemplateRoute seeding.');

            return;
        }

        $metadataSets1 = [
            ['label' => 'Material', 'name' => 'material', 'input' => 'text', 'value' => 'Kraft Paper'],
            ['label' => 'Block', 'name' => 'block', 'input' => 'text', 'value' => 'K-01'],
            ['label' => 'Ink', 'name' => 'ink', 'input' => 'text', 'value' => 'UV Ink'],
            ['label' => 'Die-cut', 'name' => 'die_cut', 'input' => 'text', 'value' => 'No'],
            ['label' => 'Dimension', 'name' => 'dimension', 'input' => 'text', 'value' => '20x30 cm'],
            ['label' => 'Die-cutting', 'name' => 'die_cutting', 'input' => 'text', 'value' => 'Rotary'],
            ['label' => 'Colour Match', 'name' => 'colour_match', 'input' => 'text', 'value' => 'Pantone 186C'],
            ['label' => 'Colour Uniformity', 'name' => 'colour_uniformity', 'input' => 'text', 'value' => 'High'],
            ['label' => 'Registration/Position', 'name' => 'registration_position', 'input' => 'text', 'value' => 'Center Aligned'],
            ['label' => 'Ink Trap', 'name' => 'ink_trap', 'input' => 'text', 'value' => '0.25 mm'],
            ['label' => 'Image Squash', 'name' => 'image_squash', 'input' => 'text', 'value' => '1%'],
            ['label' => 'Interval', 'name' => 'interval', 'input' => 'text', 'value' => '1.2 sec'],
            ['label' => 'Formation', 'name' => 'formation', 'input' => 'text', 'value' => 'Stacked'],
        ];

        $metadataSets2 = [
            ['label' => 'Material', 'name' => 'material', 'input' => 'text', 'value' => 'Art Paper'],
            ['label' => 'Block', 'name' => 'block', 'input' => 'text', 'value' => 'A-08'],
            ['label' => 'Ink', 'name' => 'ink', 'input' => 'text', 'value' => 'Water-based Ink'],
            ['label' => 'Die-cut', 'name' => 'die_cut', 'input' => 'text', 'value' => 'Yes'],
            ['label' => 'Dimension', 'name' => 'dimension', 'input' => 'text', 'value' => '25x40 cm'],
            ['label' => 'Die-cutting', 'name' => 'die_cutting', 'input' => 'text', 'value' => 'Flatbed'],
            ['label' => 'Colour Match', 'name' => 'colour_match', 'input' => 'text', 'value' => 'CMYK Standard'],
            ['label' => 'Colour Uniformity', 'name' => 'colour_uniformity', 'input' => 'text', 'value' => 'Medium'],
            ['label' => 'Registration/Position', 'name' => 'registration_position', 'input' => 'text', 'value' => 'Left Align'],
            ['label' => 'Ink Trap', 'name' => 'ink_trap', 'input' => 'text', 'value' => '0.18 mm'],
            ['label' => 'Image Squash', 'name' => 'image_squash', 'input' => 'text', 'value' => '3%'],
            ['label' => 'Interval', 'name' => 'interval', 'input' => 'text', 'value' => '1.5 sec'],
            ['label' => 'Formation', 'name' => 'formation', 'input' => 'text', 'value' => 'Rolled'],
        ];

        $metadataSets3 = [
            ['label' => 'Material', 'name' => 'material', 'input' => 'text', 'value' => 'Foil'],
            ['label' => 'Block', 'name' => 'block', 'input' => 'text', 'value' => 'F-11'],
            ['label' => 'Ink', 'name' => 'ink', 'input' => 'text', 'value' => 'Latex Ink'],
            ['label' => 'Die-cut', 'name' => 'die_cut', 'input' => 'text', 'value' => 'Yes'],
            ['label' => 'Dimension', 'name' => 'dimension', 'input' => 'text', 'value' => '18x28 cm'],
            ['label' => 'Die-cutting', 'name' => 'die_cutting', 'input' => 'text', 'value' => 'Laser'],
            ['label' => 'Colour Match', 'name' => 'colour_match', 'input' => 'text', 'value' => 'Pantone 877C'],
            ['label' => 'Colour Uniformity', 'name' => 'colour_uniformity', 'input' => 'text', 'value' => 'Premium'],
            ['label' => 'Registration/Position', 'name' => 'registration_position', 'input' => 'text', 'value' => 'Bottom Left'],
            ['label' => 'Ink Trap', 'name' => 'ink_trap', 'input' => 'text', 'value' => '0.40 mm'],
            ['label' => 'Image Squash', 'name' => 'image_squash', 'input' => 'text', 'value' => '4%'],
            ['label' => 'Interval', 'name' => 'interval', 'input' => 'text', 'value' => '0.9 sec'],
            ['label' => 'Formation', 'name' => 'formation', 'input' => 'text', 'value' => 'Custom Mold'],
        ];

        $metadataSets4 = [
            ['label' => 'Material', 'name' => 'material', 'input' => 'text', 'value' => 'Synthetic Paper'],
            ['label' => 'Block', 'name' => 'block', 'input' => 'text', 'value' => 'S-05'],
            ['label' => 'Ink', 'name' => 'ink', 'input' => 'text', 'value' => 'Solvent Ink'],
            ['label' => 'Die-cut', 'name' => 'die_cut', 'input' => 'text', 'value' => 'No'],
            ['label' => 'Dimension', 'name' => 'dimension', 'input' => 'text', 'value' => '22x35 cm'],
            ['label' => 'Die-cutting', 'name' => 'die_cutting', 'input' => 'text', 'value' => 'Rotary'],
            ['label' => 'Colour Match', 'name' => 'colour_match', 'input' => 'text', 'value' => 'Pantone 305C'],
            ['label' => 'Colour Uniformity', 'name' => 'colour_uniformity', 'input' => 'text', 'value' => 'Balanced'],
            ['label' => 'Registration/Position', 'name' => 'registration_position', 'input' => 'text', 'value' => 'Top Right'],
            ['label' => 'Ink Trap', 'name' => 'ink_trap', 'input' => 'text', 'value' => '0.30 mm'],
            ['label' => 'Image Squash', 'name' => 'image_squash', 'input' => 'text', 'value' => '2%'],
            ['label' => 'Interval', 'name' => 'interval', 'input' => 'text', 'value' => '1.0 sec'],
            ['label' => 'Formation', 'name' => 'formation', 'input' => 'text', 'value' => 'Sheeted'],
        ];

        $metadataSets5 = [
            ['label' => 'Material', 'name' => 'material', 'input' => 'text', 'value' => 'Laminated Film'],
            ['label' => 'Block', 'name' => 'block', 'input' => 'text', 'value' => 'L-17'],
            ['label' => 'Ink', 'name' => 'ink', 'input' => 'text', 'value' => 'UV Ink'],
            ['label' => 'Die-cut', 'name' => 'die_cut', 'input' => 'text', 'value' => 'Yes'],
            ['label' => 'Dimension', 'name' => 'dimension', 'input' => 'text', 'value' => '30x45 cm'],
            ['label' => 'Die-cutting', 'name' => 'die_cutting', 'input' => 'text', 'value' => 'Laser'],
            ['label' => 'Colour Match', 'name' => 'colour_match', 'input' => 'text', 'value' => 'Pantone 533C'],
            ['label' => 'Colour Uniformity', 'name' => 'colour_uniformity', 'input' => 'text', 'value' => 'High'],
            ['label' => 'Registration/Position', 'name' => 'registration_position', 'input' => 'text', 'value' => 'Center Aligned'],
            ['label' => 'Ink Trap', 'name' => 'ink_trap', 'input' => 'text', 'value' => '0.15 mm'],
            ['label' => 'Image Squash', 'name' => 'image_squash', 'input' => 'text', 'value' => '1%'],
            ['label' => 'Interval', 'name' => 'interval', 'input' => 'text', 'value' => '0.7 sec'],
            ['label' => 'Formation', 'name' => 'formation', 'input' => 'text', 'value' => 'Rolled'],
        ];

        $metadataSets = [
            [
                'order_seq' => 1,
                'route' => 'T01',
                'parameters' => $metadataSets1,
                'allow_user_count' => 2,
            ],
            [
                'order_seq' => 2,
                'route' => 'T02',
                'parameters' => $metadataSets2,
                'allow_user_count' => 2,
            ],
            [
                'order_seq' => 3,
                'route' => 'T03',
                'parameters' => $metadataSets3,
                'allow_user_count' => 1,
            ],
            [
                'order_seq' => 4,
                'route' => 'T04',
                'parameters' => $metadataSets4,
                'allow_user_count' => 2,
            ],
            [
                'order_seq' => 5,
                'route' => 'T05',
                'parameters' => $metadataSets5,
                'allow_user_count' => 2,
            ],
        ];

        $names = [
            'Generic Template',
            'Common Template',
            'Wistern Digital Template',
            'Generic Template',
            'Common Template',
        ];

        $pointer = 0;

        foreach ($metadataSets as $index => $metadata) {
            $allowUserCount = $metadata['allow_user_count'] ?? 1;
            $metadata['allow_user'] = [];

            for ($i = 0; $i < $allowUserCount; $i++) {
                $metadata['allow_user'][] = [
                    'user_id' => $supervisors[$pointer % count($supervisors)],
                ];
                $pointer++;
            }

            unset($metadata['allow_user_count']);

            TemplateRoute::create([
                'uuid' => (string) Str::uuid(),
                'template' => $names[$index] ?? 'Generic Template',
                'user_id' => $supervisors[$index % count($supervisors)],
                'metadata' => $metadata,
            ]);
        }
    }
}
