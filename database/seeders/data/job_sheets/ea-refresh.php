<?php

// REFRESH Service (18 Points) technician job sheet (REFRESH Service 18 Technician Job Sheet.xlsx).
// Generated from the owner's paper job sheet; edit here or in the Job Sheet Masters screen.

return [
    'slug' => 'ea-refresh-job-sheet',
    'name' => 'REFRESH Service (18 Points) — Technician Job Sheet',
    'sheet_kind' => 'service_activities',
    'packages' => ['ea-refresh'],
    'service_type' => null,
    'filled_by' => 'technician',
    'has_issues_tab' => true,
    'has_observations' => true,
    'sort_order' => '10',
    'sections' => [
        'Engine Compartment' => [
            ['Top up engine oil (max 1 liter)', '***'],
            ['Clean air filter element', '***'],
            ['Check air filter housing seal', ''],
            ['Clean spark plugs', '***'],
            ['Adjust spark plug electrode gap', 'Direct'],
            ['Top up brake fluid', '***'],
            ['Top up clutch fluid (if applicable)', 'Direct'],
            ['Top up power steering fluid (if applicable)', 'Direct'],
            ['Top up gear oil (if required)', 'Direct'],
            ['Top up coolant/radiator water', '***'],
            ['Top up battery distilled water', 'Direct'],
            ['Clean battery terminals', ''],
            ['Top up wiper washer fluid', '***'],
        ],
        'Cabin & Interior' => [
            ['Clean cabin AC filter', '***'],
            ['Vacuum Interiors', 'Direct'],
            ['Grease all door hinges', 'Direct'],
        ],
        'Exterior & Tyres' => [
            ['Check & inflate all tyre pressures', 'Direct'],
            ['Body Wash', 'Direct'],
        ],
    ],
];
