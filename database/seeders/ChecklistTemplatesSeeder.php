<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\ChecklistSection;
use App\Modules\Workshop\Models\ChecklistItem;

class ChecklistTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        // Create generic pre-delivery checklist (for all packages)
        $this->createPreDeliveryChecklist();

        // Create service checklists for each package
        $packages = ServicePackage::all();
        foreach ($packages as $package) {
            $this->createServiceChecklist($package);
        }
    }

    protected function createPreDeliveryChecklist(): void
    {
        $template = ChecklistTemplate::firstOrCreate(
            ['slug' => 'pre-delivery-checklist'],
            [
                'package_id' => null, // Generic, applies to all
                'name' => 'Pre-Delivery Checklist',
                'type' => 'qc',
                'sort_order' => 1,
            ]
        );

        $sections = [
            'Engine & Fluids' => [
                'Engine oil level OK',
                'Coolant level OK',
                'Brake fluid level OK',
                'Power steering fluid OK',
                'Windshield washer fluid OK',
                'No oil leaks detected',
            ],
            'Exterior Check' => [
                'Body wash completed',
                'No scratches or dents',
                'All lights working',
                'Wiper operation OK',
                'Mirrors intact',
                'Number plate clean',
            ],
            'Interior Check' => [
                'Interior cleaned',
                'All controls working',
                'AC functioning',
                'Stereo/infotainment OK',
                'Seat adjustment OK',
                'Seatbelts OK',
            ],
            'Wheels & Tyres' => [
                'Tyre pressure correct',
                'Tyre condition OK',
                'Wheel covers/alloys clean',
                'Spare tyre present',
                'Jack & tools present',
            ],
            'Documentation' => [
                'Job card completed',
                'Old parts collected (if any)',
                'Customer items returned',
                'Service sticker updated',
            ],
        ];

        $this->createSectionsAndItems($template, $sections);
    }

    protected function createServiceChecklist(ServicePackage $package): void
    {
        $template = ChecklistTemplate::firstOrCreate(
            ['slug' => $package->slug . '-service-checklist'],
            [
                'package_id' => $package->id,
                'name' => $package->name . ' Service Checklist',
                'type' => 'during_service',
                'sort_order' => 1,
            ]
        );

        // Define sections based on package level
        $sections = $this->getSectionsForPackage($package->slug);
        $this->createSectionsAndItems($template, $sections);
    }

    protected function getSectionsForPackage(string $packageSlug): array
    {
        $basicSections = [
            'Oil & Filter' => [
                'Drain old engine oil',
                'Replace oil filter',
                'Add new engine oil',
                'Check oil level',
            ],
            'Fluid Check' => [
                'Check coolant level',
                'Check brake fluid',
                'Check power steering fluid',
                'Top up washer fluid',
            ],
            'Visual Inspection' => [
                'Battery terminals clean',
                'Belt condition OK',
                'No visible leaks',
            ],
        ];

        $proSections = [
            'Brake System' => [
                'Check brake pad thickness',
                'Check brake disc condition',
                'Check brake fluid level',
                'Test brake operation',
            ],
            'Suspension' => [
                'Check shock absorbers',
                'Check suspension bushings',
                'Check steering play',
            ],
            'Wheels' => [
                'Check tyre tread depth',
                'Check tyre pressure',
                'Check wheel bearing',
            ],
        ];

        $eliteSections = [
            'Diagnostic' => [
                'OBD scan completed',
                'No error codes found',
                'ECU check OK',
            ],
            'AC System' => [
                'AC cooling check',
                'AC blower operation',
                'Cabin filter check',
            ],
            'Detailing' => [
                'Exterior wash & polish',
                'Interior deep clean',
                'Engine bay cleaning',
                'Alloy cleaning',
            ],
        ];

        return match ($packageSlug) {
            'ea-refresh' => $basicSections,
            'ea-essentials' => $basicSections,
            'ea-pro' => array_merge($basicSections, $proSections),
            'ea-360' => array_merge($basicSections, $proSections, ['Diagnostic' => $eliteSections['Diagnostic'], 'AC System' => $eliteSections['AC System']]),
            'ea-elite' => array_merge($basicSections, $proSections, $eliteSections),
            default => $basicSections,
        };
    }

    protected function createSectionsAndItems(ChecklistTemplate $template, array $sections): void
    {
        $sectionOrder = 1;
        foreach ($sections as $sectionName => $items) {
            $section = ChecklistSection::firstOrCreate(
                [
                    'template_id' => $template->id,
                    'name' => $sectionName,
                ],
                ['sort_order' => $sectionOrder++]
            );

            $itemOrder = 1;
            foreach ($items as $itemName) {
                ChecklistItem::firstOrCreate(
                    [
                        'section_id' => $section->id,
                        'item_name' => $itemName,
                    ],
                    [
                        'input_type' => 'checkbox',
                        'is_required' => false,
                        'requires_photo' => false,
                        'sort_order' => $itemOrder++,
                    ]
                );
            }
        }
    }
}
