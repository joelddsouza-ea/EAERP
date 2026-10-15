<?php

/*
 * WhatsApp messages to customers. Placeholders: :name (customer), :car (registration),
 * :job (job number), :workshop, :link (customer job page), plus per-template extras.
 */

return [
    'car_received' => "Hello :name, your car :car has been received at :workshop (Job :job).\nFollow its progress, photos and estimate here: :link",

    'work_started' => "Hello :name, work has started on your car :car (Job :job).\nTrack it here: :link",

    'estimate_ready' => "Hello :name, the estimate for your car :car is ready (Job :job, total Rs :total).\n:pending_text\nView and approve here: :link",

    'approval_reminder' => "Hello :name, :count item(s) on the estimate for your car :car are still waiting for your approval.\nPlease approve or decline here: :link",

    'decision_recorded' => "Hello :name, as discussed with :advisor, we have recorded the following on the estimate for your car :car (Job :job):\n:lines\nYou can review or change these here: :link",

    'media_shared' => "Hello :name, new photos/videos of your car :car have been added (Job :job).\nView them here: :link",

    'ready_for_delivery' => "Hello :name, your car :car is ready for delivery (Job :job).\nDetails: :link",

    'report_ready' => "Hello :name, the service report for your car :car is ready (Job :job).\nView it here: :link",

    'thank_you' => "Thank you for choosing :workshop, :name! We hope you are happy with the service on your car :car.\nYour job details remain available here: :link",

    // Fragments
    'pending_some' => ':count additional item(s) need your approval.',
    'pending_none' => 'No approval needed.',
    'decision_line' => '- :description (Rs :amount): :decision',
    'decision_approved' => 'Approved',
    'decision_declined' => 'Recommended but not approved by customer',
];
