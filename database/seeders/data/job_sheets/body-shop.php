<?php

// Body Shop job sheet: per panel Repair / Replace / Partial Paint / Full Paint. "Others" are ad-hoc rows; spares fitted go on the Issues & Jobs tab.
// Generated from the owner's paper job sheet; edit here or in the Job Sheet Masters screen.

return [
    'slug' => 'body-shop-job-sheet',
    'name' => 'Body Shop Job Sheet',
    'sheet_kind' => 'body_shop',
    'packages' => [],
    'service_type' => 'body_shop',
    'filled_by' => 'technician',
    'input_type' => 'panel_matrix',
    'required' => false,
    'has_issues_tab' => true,
    'has_observations' => true,
    'sort_order' => '10',
    'sections' => [
        'Panels' => [
            ['Front Bumper', ''],
            ['Bonnet', ''],
            ['Front Left Fender', ''],
            ['Front Right Fender', ''],
            ['Front Left Door', ''],
            ['Front Right Door', ''],
            ['Rear Left Door', ''],
            ['Rear Right Door', ''],
            ['Left Running Board', ''],
            ['Right Running Board', ''],
            ['Top', ''],
            ['Left Quarter Panel', ''],
            ['Right Quarter Panel', ''],
            ['Boot Lid', ''],
            ['Rear Bumper', ''],
        ],
    ],
];
