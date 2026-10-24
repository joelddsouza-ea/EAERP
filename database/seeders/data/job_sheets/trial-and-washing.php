<?php

// Trial drive and washing for jobs whose job sheets have neither (general repairs, AC, body shop):
// added to the job automatically. The trial drive comes before the wash; washing can be sent to an
// outside washing partner from the sheet.

return [
    'slug' => 'trial-and-washing',
    'name' => 'Trial Drive & Washing — Job Sheet',
    'sheet_kind' => 'post_service',
    'packages' => [],
    'filled_by' => 'technician',
    'sort_order' => '90',
    'outside_sections' => ['Washing & Cleaning' => 'washing'],
    'sections' => [
        'Final Checks' => [
            ['Trial drive - engine performance', 'Direct'],
            ['Trial drive - brake test', 'Direct'],
            ['Final inspection post trial', 'Direct'],
        ],
        'Washing & Cleaning' => [
            ['Vacuum Interiors', 'Direct'],
            ['Body Wash', 'Direct'],
        ],
    ],
];
