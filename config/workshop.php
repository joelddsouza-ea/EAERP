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
            'invoiced',
            'gate_pass_generated',
        ],

        // Customer notification on entering a status: 'always' or 'first_entry'
        'notify_customer' => [
            'intake_in_progress' => 'first_entry',
            'awaiting_approval' => 'always',
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

    /*
    |--------------------------------------------------------------------------
    | Intake and parts (defaults; the owner edits them in the masters screens,
    | stored in organization settings under the same keys)
    |--------------------------------------------------------------------------
    */

    'intake' => [
        // Inventory media the advisor (or driver) must capture before work can start
        'min_photos' => 4,
        'min_videos' => 1,
    ],

    'parts' => [
        // Margin added on a part's GST-inclusive cost when it goes on an estimate
        'default_margin_pct' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer channel (estimates, approvals, messages)
    |--------------------------------------------------------------------------
    */

    'customer_channel' => [
        // 'share' = advisor sends from the workshop phone via a wa.me link; 'api' = WhatsApp Business API
        'whatsapp_driver' => env('WHATSAPP_DRIVER', 'share'),

        // Customer links stay valid this many days after delivery (or creation, while the job is open)
        'link_valid_days' => 30,

        // Job statuses in which the customer may still change approval decisions
        'decision_statuses' => ['intake_in_progress', 'awaiting_approval', 'work_in_progress'],

        // Message template for each status the customer is told about (see lang/en/customer_messages.php)
        'status_templates' => [
            'intake_in_progress' => 'car_received',
            'work_in_progress' => 'work_started',
            'ready_for_delivery' => 'ready_for_delivery',
            'delivered' => 'thank_you',
        ],
    ],

];
