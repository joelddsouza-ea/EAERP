<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — nurturing and retention:
 *  - creatives (image / video / PDF / text with a caption) and journeys: timed steps that
 *    send a creative (or create a follow-up task) after a trigger;
 *  - enrolments: a lead or a customer's car going through a journey;
 *  - login-free CRM links (self-booking, feedback, a creative to view) with open tracking;
 *  - feedback after delivery, referrals and their reward;
 *  - the message log also holds messages to leads, with the creative and when it is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creatives', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            // image | video | pdf | text
            $table->string('kind', 6)->default('text');
            $table->string('file_disk', 30)->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime', 100)->nullable();
            // The message; placeholders :name :car :plan :price :book_link :feedback_link :referral_link :due_date :workshop :offer :view_link
            $table->text('caption');
            // A plan's brochure: sent with that plan's quote
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            // For the WhatsApp Business API later (Interakt): approved template and its language
            $table->string('wa_template', 100)->nullable();
            $table->string('wa_language', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journeys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // new_lead | lost_not_now | no_show | after_delivery | service_due | insurance_due | pending_work | win_back | referral_thanks
            $table->string('trigger', 20);
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['trigger', 'is_active']);
        });

        Schema::create('journey_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            // Days after the start (or before / after the due date for service_due, insurance_due: negative = before)
            $table->smallInteger('day_offset');
            $table->time('send_at')->default('10:30:00');
            // message | task (a follow-up for the executive) | lead (open a lead for the executive)
            $table->string('action', 8)->default('message');
            $table->foreignId('creative_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_note', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('journey_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            // Day 0 of the journey (start, or the due date)
            $table->date('anchor_date');
            $table->unsignedSmallInteger('next_step')->default(0);
            $table->timestamp('next_run_at')->nullable();
            // active | done | stopped
            $table->string('status', 8)->default('active');
            $table->string('stop_reason', 100)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
            $table->index(['lead_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('crm_links', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            // book | feedback | creative | referral
            $table->string('purpose', 10);
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creative_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamps();

            $table->index(['purpose', 'lead_id']);
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comments')->nullable();
            $table->timestamp('review_clicked_at')->nullable();
            // A low rating: the manager calls back
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referred_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            // pending | rewarded
            $table->string('status', 10)->default('pending');
            $table->decimal('reward_amount', 10, 2)->default(0);
            $table->foreignId('customer_credit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('customer_id')->constrained()->cascadeOnDelete();
            // job (job-card messages) | crm (quotes, journeys, bookings, feedback)
            $table->string('purpose', 8)->default('job')->after('template');
            $table->foreignId('creative_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
            $table->foreignId('journey_enrollment_id')->nullable()->after('creative_id')->constrained()->nullOnDelete();
            $table->string('media_url', 500)->nullable()->after('body');
            // Due from (a journey step at its time)
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->foreignId('assigned_to')->nullable()->after('scheduled_at')->constrained('users')->nullOnDelete();

            $table->index(['purpose', 'status', 'scheduled_at']);
        });
        DB::statement('ALTER TABLE customer_messages MODIFY customer_id BIGINT UNSIGNED NULL');

        $this->seedStarterJourneys();
    }

    /**
     * Starter creatives and journeys the owner edits (Masters › CRM).
     */
    protected function seedStarterJourneys(): void
    {
        $now = now();
        $creative = function (string $title, string $caption) use ($now) {
            return DB::table('creatives')->insertGetId(['title' => $title, 'kind' => 'text', 'caption' => $caption, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        };
        $journey = function (string $name, string $trigger, string $description, array $steps) use ($now) {
            $id = DB::table('journeys')->insertGetId(['name' => $name, 'trigger' => $trigger, 'description' => $description, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($steps as $i => [$day, $action, $creativeId, $note]) {
                DB::table('journey_steps')->insert([
                    'journey_id' => $id, 'day_offset' => $day, 'send_at' => '10:30:00', 'action' => $action, 'creative_id' => $creativeId,
                    'task_note' => $note, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        };

        $welcome = $creative('Welcome + plans', "Hi :name, thank you for contacting :workshop! 🚗\nOur service plans for your :car start at :price. Pick a time that suits you here: :book_link\nFree pickup & drop available.");
        $nudge = $creative('Reminder with offer', "Hi :name, still planning your car's service? Book this week and get a complimentary car wash :offer. Book in 30 seconds: :book_link");
        $why = $creative('Why Express Autocare', "Hi :name, genuine parts, photo/video updates of every job and a fixed price before we start. See for yourself: :book_link");
        $monthly = $creative('Monthly check-in', "Hi :name, is your :car due for a service? Slots are open this week: :book_link");
        $noShow = $creative('Missed booking', "Hi :name, we missed you today! Shall we fix another time for your :car? :book_link");
        $thanks = $creative('Thank you + feedback', "Thank you for choosing :workshop, :name! How was your experience with your :car? Rate us in one tap: :feedback_link\nRefer a friend and you both get a reward: :referral_link");
        $due = $creative('Service due', "Hi :name, your :car is due for its next service on :due_date. Book a slot (free pickup): :book_link");
        $overdue = $creative('Service overdue', "Hi :name, your :car's service is overdue. Regular service keeps it safe and saves money later. Book here: :book_link");
        $pending = $creative('Pending work reminder', "Hi :name, during your last visit we noticed work your :car still needs. Let's take care of it before it costs more: :book_link");
        $winBack = $creative('We miss you', "Hi :name, it's been a while since we saw your :car! Here's a special offer to welcome you back :offer: :book_link");
        $insurance = $creative('Insurance renewal', "Hi :name, your :car's insurance expires on :due_date. We can help you renew and handle any claim repairs. Reply to this message or book: :book_link");
        $referral = $creative('Referral thank-you', "Thank you :name! Your friend serviced their car with us — your referral reward has been added to your account. 🎉");

        $journey('New lead — not booked yet', 'new_lead', 'Plan details at once, then reminders every 1–2 weeks for 2 months, then monthly.', [
            [0, 'message', $welcome, null],
            [2, 'message', $nudge, null],
            [7, 'message', $why, null],
            [14, 'task', null, 'Call the lead again'],
            [21, 'message', $nudge, null],
            [35, 'message', $why, null],
            [60, 'message', $monthly, null],
            [90, 'message', $monthly, null],
            [120, 'message', $monthly, null],
            [180, 'message', $monthly, null],
        ]);
        $journey('Lost — not now', 'lost_not_now', 'Leads closed as "not now": a gentle monthly reminder.', [
            [30, 'message', $monthly, null], [60, 'message', $nudge, null], [120, 'message', $monthly, null],
        ]);
        $journey('Missed booking', 'no_show', 'Booked but did not come.', [
            [0, 'message', $noShow, null], [1, 'task', null, 'Call to rebook'], [7, 'message', $nudge, null],
        ]);
        $journey('After delivery', 'after_delivery', 'Thank you, feedback and the referral link.', [
            [1, 'message', $thanks, null],
        ]);
        $journey('Next service due', 'service_due', 'Days before (−) / after (+) the next service date.', [
            [-21, 'message', $due, null], [-7, 'message', $due, null], [0, 'lead', null, 'Service due today — call to book'],
            [7, 'message', $overdue, null], [30, 'message', $overdue, null],
        ]);
        $journey('Pending work', 'pending_work', 'Work the customer did not approve, after delivery.', [
            [14, 'message', $pending, null], [21, 'lead', null, 'Pending work — call to book'], [45, 'message', $pending, null],
        ]);
        $journey('Win back', 'win_back', 'No visit for a long time.', [
            [0, 'message', $winBack, null], [14, 'lead', null, 'Win-back call'], [45, 'message', $winBack, null],
        ]);
        $journey('Insurance renewal', 'insurance_due', 'Days before (−) the insurance expiry.', [
            [-30, 'message', $insurance, null], [-7, 'lead', null, 'Insurance renewal — call'],
        ]);
        $journey('Referral thank-you', 'referral_thanks', 'When a referral earns its reward.', [
            [0, 'message', $referral, null],
        ]);
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'status', 'scheduled_at']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('journey_enrollment_id');
            $table->dropConstrainedForeignId('creative_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn(['purpose', 'media_url', 'scheduled_at']);
        });
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('crm_links');
        Schema::dropIfExists('journey_enrollments');
        Schema::dropIfExists('journey_steps');
        Schema::dropIfExists('journeys');
        Schema::dropIfExists('creatives');
    }
};
