<?php

// Pre-delivery checklist filled by the advisor. "expect" is the answer that passes.
// Generated from the owner's paper job sheet; edit here or in the Job Sheet Masters screen.

return [
    'slug' => 'pre-delivery-job-sheet',
    'name' => 'Pre-Delivery Checklist',
    'sheet_kind' => 'pre_delivery',
    'packages' => [],
    'service_type' => null,
    'applies_to_all' => true,
    'filled_by' => 'advisor',
    'input_type' => 'yes_no',
    'has_issues_tab' => false,
    'has_observations' => true,
    'sort_order' => '30',
    'sections' => [
        'Pre-Delivery' => [
            ['Is the Engine Compartment washed properly?', '', 'expect' => 'yes'],
            ['Are there any signs of oil or coolant leakage in the Engine Compartment?', '', 'expect' => 'no'],
            ['Are all the Wheel Arcs washed properly?', '', 'expect' => 'yes'],
            ['Are there any signs of dust in the cabin?', '', 'expect' => 'no'],
            ['Is the dashboard and the door trims polished properly?', '', 'expect' => 'yes'],
            ['Do the door trims or seats have any marks of grease or oil left by technicians?', '', 'expect' => 'no'],
            ['Are the door mats washed properly?', '', 'expect' => 'yes'],
            ['Have the floor carpets, the seats and door crevices vacuumed properly?', '', 'expect' => 'yes'],
            ['Has the Glove Box been vacuumed properly?', '', 'expect' => 'yes'],
            ['Have all the glasses been wiped properly inside and outside?', '', 'expect' => 'yes'],
            ['Are there any signs of oil or grease on the car exterior?', '', 'expect' => 'no'],
            ['Have the tyres been polished?', '', 'expect' => 'yes', 'packages' => ['ea-360', 'ea-elite']],
            ['Are all the wheel caps in place and washed properly?', '', 'expect' => 'yes'],
            ['Is the AC Disinfectant sprayed?', '', 'expect' => 'yes', 'packages' => ['ea-360', 'ea-elite']],
            ['Has the Battery Terminal Spray been applied?', '', 'expect' => 'yes', 'packages' => ['ea-360', 'ea-elite']],
            ['Has the Next Service Due sticker been put on the door sleeve and with proper figures?', '', 'expect' => 'yes'],
            ['Has the EXPRESS AUTOCARE sticker been put on the rear of the car?', '', 'expect' => 'yes'],
            ['Has the Service Light and/or Service KMs been reset in the car?', '', 'expect' => 'yes'],
            ['Has the Clock & Radio been set?', '', 'expect' => 'yes'],
            ['Is the Car checked for any leftover Tools, Nuts, Bolts or any other items?', '', 'expect' => 'yes'],
        ],
    ],
];
