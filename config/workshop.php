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
        ],

        // Customer notification on entering a status: 'always' or 'first_entry'.
        // Fallback only: the Job stages master (workflow_stages) decides for its stages.
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

    // Phase 3 purchases module (GRN, part verification, consumables stock, supplier dues).
    // Optional: switched on by the owner in Masters › Purchases & Stock.
    'purchases' => [
        'enabled' => false,
        // Photos of each job part on its GRN line (the technician checks it against these)
        'min_part_photos' => 1,
    ],

    'intake' => [
        // Inventory media the advisor (or driver) must capture before work can start
        'min_photos' => 4,
        'min_videos' => 1,
    ],

    'issues' => [
        // Additional Issues lines: photos/videos of a problem the technician found, and of a fitted new part
        'min_problem_media' => 1,
        'min_fitted_media' => 1,
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

    'billing' => [
        // Master/estimate prices include GST at this rate; it is extracted on the bill
        'inclusive_gst_rate' => 18,

        // Default HSN/SAC for bill lines, by words in the description (first match wins).
        // Back office can change a line's code on the proforma.
        'hsn_rules' => [
            'engine oil|motor oil|gear oil' => '27101980',
            'oil filter|air filter|ac filter|cabin filter|fuel filter|filter' => '84212300',
            'coolant|antifreeze' => '38200000',
            'brake fluid' => '38190010',
            'treatment|additive|flush' => '38112900',
            'kit|consumable|lubricant|grease' => '34031900',
            'battery' => '85071000',
            'bulb|lamp' => '85392910',
            'wiper' => '85124000',
            'spark plug' => '85111000',
            'tyre|tire' => '40111010',
            'brake pad|brake shoe|disc|rotor' => '87083000',
        ],
        'hsn_parts' => '87089900',
        'sac_services' => '998714',

        'place_of_supply' => env('WORKSHOP_PLACE_OF_SUPPLY', 'Maharashtra'),
        'terms' => "1. Payment is due on delivery; the car leaves only with a gate pass.\n2. Workmanship guaranteed for 30 days.\n3. Parts warranty as per the manufacturer's terms.",
    ],

    'customer_channel' => [
        // 'share' = advisor sends from the workshop phone via a wa.me link; 'api' = WhatsApp Business API
        'whatsapp_driver' => env('WHATSAPP_DRIVER', 'share'),

        // Customer links stay valid this many days after delivery (or creation, while the job is open)
        'link_valid_days' => 30,

        // Job statuses in which the customer may still change approval decisions
        'decision_statuses' => ['intake_in_progress', 'awaiting_approval', 'work_in_progress', 'awaiting_parts'],

        // Message template for each status the customer is told about (see lang/en/customer_messages.php)
        'status_templates' => [
            'intake_in_progress' => 'car_received',
            'work_in_progress' => 'work_started',
            'ready_for_delivery' => 'ready_for_delivery',
            'delivered' => 'thank_you',
        ],
    ],

];
