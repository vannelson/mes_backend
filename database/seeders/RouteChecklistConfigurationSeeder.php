<?php

namespace Database\Seeders;

use App\Models\RouteChecklistConfiguration;
use Illuminate\Database\Seeder;

class RouteChecklistConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'route_type' => 'lp',
                'title' => 'LP Checklist',
                'description' => 'Letterpress setup, print quality, and label verification.',
                'fields' => [
                    $this->okNg('plateCondition', 'Plate / Block condition'),
                    $this->okNg('inkColor', 'Ink color matches standard'),
                    $this->okNg('registration', 'Registration / position'),
                    $this->okNg('printQuality', 'Print quality'),
                    $this->dimension('dimension', 'Product dimensions'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'flexo',
                'title' => 'Flexo Checklist',
                'description' => 'Flexographic print checks for plate, color, and registration.',
                'fields' => [
                    $this->okNg('plateCylinder', 'Plate / cylinder setup'),
                    $this->okNg('aniloxRoll', 'Anilox / ink transfer'),
                    $this->okNg('colourMatch', 'Colour match'),
                    $this->okNg('registration', 'Registration'),
                    $this->okNg('printDefects', 'No print defects'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'digital',
                'title' => 'Digital Checklist',
                'description' => 'Artwork, color, and digital print output checks.',
                'fields' => [
                    $this->radio('artworkFile', 'Artwork / file correctness', ['Correct', 'Incorrect']),
                    $this->okNg('colourMatch', 'Colour match'),
                    $this->okNg('imageQuality', 'Image quality'),
                    $this->okNg('tonerInk', 'Ink / toner condition'),
                    $this->okNg('registration', 'Registration / position'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'diecut',
                'title' => 'Diecut Checklist',
                'description' => 'Diecut accuracy, clearance, and product dimension checks.',
                'fields' => [
                    $this->okNg('dieCondition', 'Die condition'),
                    $this->okNg('cuttingAccuracy', 'Cutting accuracy'),
                    $this->dimension('dimension', 'Product dimensions'),
                    $this->okNg('matrixRemoval', 'Matrix removal'),
                    $this->okNg('edgeQuality', 'Edge quality'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'aoi',
                'title' => 'AOI Checklist',
                'description' => 'Automated optical inspection setup and reject verification.',
                'fields' => [
                    $this->okNg('cameraSetup', 'Camera setup'),
                    $this->okNg('masterSample', 'Master sample loaded'),
                    $this->okNg('defectDetection', 'Defect detection'),
                    $this->number('rejectQty', 'Reject quantity', false),
                    $this->okNg('inspectionResult', 'Inspection result'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'inspection',
                'title' => 'Inspection Checklist',
                'description' => 'Manual inspection and quality disposition checks.',
                'fields' => [
                    $this->okNg('visualCheck', 'Visual check'),
                    $this->okNg('labelContent', 'Label content'),
                    $this->okNg('barcodeQr', 'Barcode / QR readability'),
                    $this->number('sampleQty', 'Sample quantity', false),
                    $this->radio('disposition', 'Disposition', ['Pass', 'Hold', 'Fail']),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'slitting',
                'title' => 'Slitting Checklist',
                'description' => 'Slitting width, roll direction, and winding checks.',
                'fields' => [
                    $this->okNg('bladeCondition', 'Blade condition'),
                    $this->okNg('slitWidth', 'Slit width'),
                    $this->okNg('rollDirection', 'Roll direction'),
                    $this->okNg('windingQuality', 'Winding quality'),
                    $this->number('rollCount', 'Roll count', false),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'rolling',
                'title' => 'Rolling Checklist',
                'description' => 'Roll quantity, core, direction, and packing readiness.',
                'fields' => [
                    $this->number('qtyPerRoll', 'Qty per roll'),
                    $this->okNg('coreSize', 'Core size'),
                    $this->okNg('rollDirection', 'Roll direction'),
                    $this->okNg('rollLabel', 'Roll label'),
                    $this->okNg('rollCondition', 'Roll condition'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
            [
                'route_type' => 'packing',
                'title' => 'Packing Checklist',
                'description' => 'Carton, label, seal, and shipment readiness checks.',
                'fields' => [
                    $this->okNg('innerBagSealed', 'Inner bag sealed'),
                    $this->okNg('outerBagSealed', 'Outer bag sealed'),
                    $this->number('cartonCount', 'Carton count'),
                    $this->okNg('cartonLabel', 'Carton label'),
                    $this->okNg('packingQty', 'Packing quantity verified'),
                    $this->text('remarks', 'Remarks', false),
                ],
            ],
        ];

        foreach ($configs as $idx => $config) {
            RouteChecklistConfiguration::updateOrCreate(
                ['route_type' => $config['route_type']],
                [
                    ...$config,
                    'is_active' => true,
                    'sort_order' => ($idx + 1) * 10,
                ],
            );
        }
    }

    protected function okNg(string $key, string $label, bool $required = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'ok_ng',
            'options' => [],
            'required' => $required,
            'width' => 1,
        ];
    }

    protected function radio(string $key, string $label, array $options, bool $required = true): array
    {
        return compact('key', 'label', 'options') + [
            'type' => 'radio',
            'required' => $required,
            'width' => 1,
        ];
    }

    protected function text(string $key, string $label, bool $required = true): array
    {
        return compact('key', 'label') + [
            'type' => 'text',
            'required' => $required,
            'width' => 2,
        ];
    }

    protected function number(string $key, string $label, bool $required = true): array
    {
        return compact('key', 'label') + [
            'type' => 'number',
            'required' => $required,
            'width' => 1,
        ];
    }

    protected function dimension(string $key, string $label): array
    {
        return compact('key', 'label') + [
            'type' => 'dimension',
            'lengthKey' => "{$key}Length",
            'widthKey' => "{$key}Width",
            'unit' => 'mm',
            'required' => true,
            'width' => 2,
        ];
    }
}
