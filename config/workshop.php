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
        'disabled_statuses' => [],

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

    // Optional modules the owner can switch off (Settings › Modules); purchases has its own switch above
    'modules' => [
        'accounts' => true,
        'crm' => true,
        'hr' => true,
        'customer_app' => true,
        'fleet' => true,
    ],

    // Phase 6 attendance & payroll (all editable in Masters › HR & Payroll)
    'hr' => [
        // Punches: GPS fix no worse than this, selfie shot within this many seconds
        'max_gps_accuracy_m' => 100,
        'selfie_window_seconds' => 30,
        // OT counts only the time after this many minutes past the shift end, rounded down
        'ot_threshold_minutes' => 60,
        'ot_rounding_minutes' => 15,
        // Defaults when an employee has no rate of their own
        'default_ot_rate' => 100,
        // Extra pay for working on a weekly off or a holiday (per day)
        'default_offday_amount' => 500,

        // Live location from punch in to punch out (Attendance & payroll › Live map)
        'tracking' => [
            'enabled' => true,
            // The phone sends a position this often while still, or after moving this far
            'interval_seconds' => 60,
            'distance_filter_m' => 50,
            // Positions rougher than this are ignored
            'max_accuracy_m' => 150,
            // No position for this long shows "No signal" on the map
            'stale_minutes' => 10,
            // Staying within 100 m this long counts as a stop on the day's trail
            'stop_minutes' => 10,
            // Positions are kept this many days
            'retention_days' => 90,
        ],

        // Statutory deductions (one switch for all; each employee has PT / PF / ESI ticks)
        'statutory' => [
            'enabled' => true,
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            // PF on the wage up to this ceiling (0 = no ceiling)
            'pf_wage_ceiling' => 15000,
            'esi_employee_rate' => 0.75,
            'esi_employer_rate' => 3.25,
            // ESI applies while the month's gross is at most this
            'esi_gross_limit' => 21000,
            // Maharashtra professional tax: [gross up to, monthly tax]; null = above the last
            'pt_slabs_male' => [[7500, 0], [10000, 175], [null, 200]],
            'pt_slabs_female' => [[25000, 0], [null, 200]],
            // February's PT for the top slab
            'pt_february_top' => 300,
        ],
    ],

    // Phase 7 CRM (editable in Settings › CRM)
    'crm' => [
        // Phase 11: WhatsApp through Interakt, handled in the CRM (CRM › WhatsApp settings)
        'whatsapp' => [
            // A new number writing in becomes a lead (round-robin like other leads)
            'auto_lead' => true,
            // Contacts (traits + tags) and events (LeadCreated, JobDelivered …) sent to Interakt
            'sync_contacts' => true,
            'sync_events' => true,
            // Also give the chat to the same person in Interakt's own inbox (their login e-mail)
            'assign_in_interakt' => false,
        ],
        // First contact with a new lead within this many minutes (working hours below)
        'sla_minutes' => 15,
        'work_start' => '09:30',
        'work_end' => '19:00',
        // Messages are only scheduled between these times
        'quiet_start' => '09:30',
        'quiet_end' => '20:00',
        // At most one marketing message per person in this many days (bookings and feedback don't count)
        'frequency_cap_days' => 3,
        // A booked car that has not come this many hours after its slot is a no-show
        'no_show_after_hours' => 3,
        // A customer with no visit for this many months gets the win-back journey
        'win_back_months' => 9,
        // Credited to the referrer when the referred friend's first job is delivered
        'referral_reward' => 500,
        // Asked of customers who rate 4 or 5
        'google_review_url' => '',
        // Used as :offer in creatives
        'offer_text' => '',
        // Self-booking: how many days ahead slots are offered; links stay valid this long
        'booking_days_ahead' => 14,
        'link_valid_days' => 45,
        'messages' => [
            'booking_confirmed' => "Hi :name, your booking :number at :workshop is confirmed for :date, :slot. :mode_line\nSee you then! To change it: :book_link",
            'booking_reminder' => "Hi :name, a reminder: your :car is booked for tomorrow (:date, :slot) at :workshop. :mode_line",
            'plan_quote' => "Hi :name, here are our :plan prices for your :car:\n:prices\n:inclusions\nBook a slot in 30 seconds: :book_link",
            // CRM › WhatsApp inbox: re-opens a chat after 24 hours (only :name and :workshop)
            'chat_opener' => 'Hello :name, this is :workshop. We have an update for you. Please reply to this message so we can continue here on WhatsApp.',
        ],
    ],

    'accounts' => [
        // Bills, receipts and GRNs dated before this are covered by the opening balances (null = everything)
        'books_start_date' => null,
    ],

    // Phase 5 pickup / drop and home service
    'trips' => [
        // GST-inclusive charges offered when a trip is booked (0 = free); ticked per trip
        'pickup_charge' => 0,
        'drop_charge' => 0,
        'home_visit_charge' => 0,
        // Sent with the pickup / home-visit booking message
        'policy_text' => "Pickup & drop policy: please keep the RC, insurance and spare key ready, and remove valuables from the car. "
            . "Photos and videos of the car are taken at pickup and shared with you. The car is returned only after the bill is paid.",
        // Photos the driver must take when handing the car back (front, rear, left, right)
        'min_drop_photos' => 4,
        // Photos the technician must take to close a home visit
        'min_home_close_photos' => 2,
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
        // Default only: Settings › WhatsApp decides ('share' = wa.me links from the workshop phone,
        // 'api' = WhatsApp Business API through Interakt)
        'whatsapp_driver' => env('WHATSAPP_DRIVER', 'share'),

        // Customer links stay valid this many days after delivery (or creation, while the job is open)
        'link_valid_days' => 30,

        // Job statuses in which the customer may still change approval decisions
        'decision_statuses' => ['intake_in_progress', 'awaiting_approval', 'work_in_progress', 'awaiting_parts'],

        // Message template for each status the customer is told about (see lang/en/customer_messages.php)
        'status_templates' => [
            'pickup_scheduled' => 'pickup_booked',
            'pickup_en_route' => 'pickup_on_the_way',
            'vehicle_collected' => 'pickup_collected',
            'home_visit_scheduled' => 'home_visit_booked',
            'home_visit_en_route' => 'home_visit_on_the_way',
            'intake_in_progress' => 'car_received',
            'work_in_progress' => 'work_started',
            'ready_for_delivery' => 'ready_for_delivery',
            'delivered' => 'thank_you',
        ],
    ],

    // "Send update" by staff (technician after a job-sheet section, driver after a trip step):
    // the advisor gets the message ready and sends it. Editable in Job Stages & Messages.
    'updates' => [
        'section_message' => "Hello :name, update on your car :car (Job :job): :section is done.\nPhotos and videos: :link",
        'stage_message' => "Hello :name, update on your car :car (Job :job): :stage.\nDetails: :link",
    ],

    // Phase 8: WhatsApp Business API through Interakt (Settings › WhatsApp, owner only).
    // Off until the owner switches it on; the API key is stored encrypted.
    'whatsapp' => [
        'provider' => env('WHATSAPP_DRIVER', 'share') === 'api' ? 'interakt' : 'share',
        'interakt' => [
            'base_url' => env('INTERAKT_BASE_URL', 'https://api.interakt.ai'),
            'api_key' => null,
            'webhook_secret' => null,
            'country_code' => '+91',
            'enabled_at' => null,
            'signing_secret' => null,
        ],
    ],

    // Phase 9: the address phones and customers reach the ERP at (Settings › App address & links;
    // empty = this server's own address), and the customer app
    'app' => [
        'public_url' => null,
    ],
    'customer_app' => [
        // Pay by UPI: the workshop's UPI ID and the name shown in the customer's UPI app
        'upi_id' => null,
        'upi_name' => null,
        'support_phone' => null,
        // Sign-in codes stay valid this long
        'otp_minutes' => 10,
        // Notifications on the installed web app (web push)
        'push_enabled' => true,
    ],

    // Phase 10: cab fleets (Settings › App Address & Customer App › Fleet cabs)
    'fleet' => [
        // A driver may approve extra work up to this total (₹, GST-inclusive) per job; a fleet can have its own
        'driver_limit' => 2000,
        // Warn the owner and drivers when a cab is this close to its next service (km)
        'service_due_km_warning' => 500,
    ],

    // Phase 9: the customer app's AI assistant (Claude). Off until the owner adds an API key
    // and switches it on in Settings › AI assistant.
    'ai' => [
        'enabled' => false,
        'api_key' => null,
        'model' => 'claude-opus-5-5',
        // Questions per customer per day (estimate explanations don't count)
        'daily_limit' => 10,
        // The assistant pauses when the month's cost reaches this (0 = no limit)
        'monthly_budget_inr' => 3000,
        // For showing the cost in rupees
        'usd_inr' => 88,
        // Rough body-shop prices the assistant may quote as ranges (owner's text)
        'body_shop_guide' => '',
    ],

];
