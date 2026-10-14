<?php

// AC Service & Repairs job sheet. The paper sheet has no markers, so steps are tick-only (Direct); "Others" are added as ad-hoc rows.
// Generated from the owner's paper job sheet; edit here or in the Job Sheet Masters screen.

return [
    'slug' => 'ac-service-job-sheet',
    'name' => 'AC Service & Repairs — Technician Job Sheet',
    'sheet_kind' => 'ac_service',
    'packages' => [],
    'service_type' => 'ac_service',
    'filled_by' => 'technician',
    'has_issues_tab' => true,
    'has_observations' => true,
    'sort_order' => '10',
    'sections' => [
        'Engine Compartment' => [
            ['AC Compressor', ''],
            ['AC Condenser', ''],
            ['Rubber Pipe & Aluminum Pipe', ''],
            ['Extension Valves', ''],
            ['Compressor Valves', ''],
            ['AC Fan', ''],
            ['R134A Gas', ''],
        ],
        'Cabin' => [
            ['Dashboard Removal (Only Some Cars)', '', 'na' => true],
            ['Cooling & Heater Coil', ''],
            ['Cooling Coil Washed & Serviced', ''],
            ['All Lines are Vacuumed', ''],
            ['AC Oil Replaced', ''],
            ['Leak Test Nitrogen Gas', ''],
            ['AC Gas Charging', ''],
            ['Compressor is checked direct with Battery To Check Compressor Pressure', ''],
        ],
    ],
];
