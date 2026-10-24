<?php

// ESSENTIALS Service (25 Points) technician job sheet (ESSENTIALS Service 25 Technician Job Sheet.xlsx).
// Generated from the owner's paper job sheet; edit here or in the Job Sheet Masters screen.

return [
    'slug' => 'ea-essentials-job-sheet',
    'name' => 'ESSENTIALS Service (25 Points) — Technician Job Sheet',
    'sheet_kind' => 'service_activities',
    'packages' => ['ea-essentials'],
    'service_type' => null,
    'filled_by' => 'technician',
    'has_issues_tab' => true,
    'has_observations' => true,
    'sort_order' => '10',
    'outside_sections' => ['Washing & Cleaning' => 'washing'],
    'sections' => [
        'Engine Compartment' => [
            ['Drain old engine oil completely', '***'],
            ['Replace engine oil', '***'],
            ['Replace oil filter', '***'],
            ['Clean air filter element', '***'],
            ['Check air filter housing seal', ''],
            ['Clean spark plugs', 'NA Button ***'],
            ['Check & adjust spark plug electrode gap', 'NA Button Direct'],
            ['Top up brake fluid', '***'],
            ['Top up clutch fluid (if applicable)', 'Direct & NA Button'],
            ['Top up power steering fluid (if applicable)', 'Direct & NA Button'],
            ['Top up gear oil (if required)', 'Direct & NA Button'],
            ['Top up coolant/radiator water', '***'],
            ['Top up battery distilled water', 'Direct'],
            ['Clean battery terminals', 'Direct'],
            ['Top up wiper washer fluid', '***'],
        ],
        'Cabin & Interior' => [
            ['Clean cabin AC filter', 'Direct'],
            ['Check AC filter housing', 'Direct'],
        ],
        'Exterior & Tyres' => [
            ['Check & inflate all tyre pressures', 'Direct'],
        ],
        // The trial drive comes before washing (owner, 2026-10-24)
        'Final Checks' => [
            ['Trial drive - engine performance', 'Direct'],
        ],
        'Washing & Cleaning' => [
            ['Vacuum Interiors', 'Direct'],
            ['Dashboard wipe down', 'Direct'],
            ['Body Wash', 'Direct'],
            ['Grease all door hinges', 'Direct'],
            ['Grease boot/tailgate hinges', 'Direct'],
            ['Reset Service Light', 'Direct'],
        ],
    ],
];
