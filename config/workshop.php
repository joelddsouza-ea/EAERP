<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Job Card Workflow
    |--------------------------------------------------------------------------
    |
    | Statuses whose features are not built yet. Transitions route around them
    | automatically (e.g. ready_for_delivery -> delivered while invoiced and
    | gate_pass_generated are disabled). Remove a status here to enable it.
    |
    */

    'workflow' => [
        'disabled_statuses' => [
            'pickup_scheduled',
            'pickup_en_route',
            'vehicle_collected',
            'awaiting_approval',
            'invoiced',
            'gate_pass_generated',
        ],

        // Customer notification on entering a status: 'always' or 'first_entry'
        'notify_customer' => [
            'intake_in_progress' => 'first_entry',
            'work_in_progress' => 'first_entry',
            'ready_for_delivery' => 'always',
            'delivered' => 'always',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Sheets
    |--------------------------------------------------------------------------
    */

    'job_sheets' => [
        // Media requirement seeded for activities marked *** in the paper job sheets
        'star_default_media' => 'photo_or_video',

        // Upload limits (kilobytes / seconds)
        'max_photo_kb' => 10240,
        'max_video_kb' => 51200,
        'max_video_seconds' => 60,

        // Lifetime of signed media URLs (minutes)
        'media_url_ttl' => 30,
    ],

];
