<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — employees, attendance and leave:
 *  - the workshop's location and punch radius on each branch;
 *  - an employee profile per user (pay, OT, statutory switches, bank, shift, weekly off);
 *  - shifts and holidays;
 *  - selfie + GPS punches (in / out) and the day they add up to;
 *  - leave types, yearly balances and leave requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('gstin');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->unsignedSmallInteger('geofence_radius_m')->default(150)->after('lng');
            // 0 = Sunday … 6 = Saturday
            $table->unsignedTinyInteger('weekly_off_day')->default(0)->after('geofence_radius_m');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_grace_minutes')->default(10);
            // Worked minutes below this make a half day
            $table->unsignedSmallInteger('half_day_minutes')->default(240);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code', 20)->nullable()->unique();
            $table->string('designation', 80)->nullable();
            $table->string('gender', 10)->default('male');
            $table->date('date_joined')->nullable();
            $table->date('date_left')->nullable();
            $table->decimal('monthly_salary', 12, 2)->default(0);
            // PF wage (basic + DA); null = the whole salary
            $table->decimal('basic_salary', 12, 2)->nullable();
            // null = the default in the payroll masters
            $table->decimal('ot_hourly_rate', 10, 2)->nullable();
            $table->decimal('offday_work_amount', 10, 2)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            // null = the branch's weekly off
            $table->unsignedTinyInteger('weekly_off_day')->nullable();
            $table->boolean('pt_enabled')->default(true);
            $table->boolean('pf_enabled')->default(false);
            $table->boolean('esi_enabled')->default(false);
            $table->string('uan', 20)->nullable();
            $table->string('esic_no', 20)->nullable();
            $table->string('pan', 12)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('bank_ifsc', 15)->nullable();
            $table->boolean('attendance_required')->default(true);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            // null = every branch
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 100);
            $table->timestamps();

            $table->index('date');
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            // in | out
            $table->string('type', 3);
            $table->timestamp('punched_at');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('selfie_disk', 30)->nullable();
            $table->string('selfie_path')->nullable();
            $table->string('selfie_thumb_path')->nullable();
            // selfie | trip | manual
            $table->string('source', 10)->default('selfie');
            $table->foreignId('job_trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->string('device', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'work_date']);
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->timestamp('first_in')->nullable();
            $table->timestamp('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            // present | half_day | absent | weekly_off | holiday | leave_paid | leave_unpaid | incomplete
            $table->string('status', 14)->default('absent');
            $table->boolean('worked_off_day')->default(false);
            $table->foreignId('leave_request_id')->nullable();
            $table->boolean('regularised')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['branch_id', 'date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 10)->unique();
            $table->boolean('is_paid')->default(true);
            $table->decimal('annual_quota', 5, 1)->default(0);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('max_carry', 5, 1)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('allotted', 5, 1)->default(0);
            $table->decimal('carried', 5, 1)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            // Days beyond the paid balance, taken as leave without pay
            $table->decimal('unpaid_days', 5, 1)->default(0);
            $table->string('reason', 500)->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['from_date', 'to_date']);
        });

        DB::table('shifts')->insert([
            'name' => 'General',
            'start_time' => '09:30:00',
            'end_time' => '19:00:00',
            'break_minutes' => 30,
            'late_grace_minutes' => 10,
            'half_day_minutes' => 240,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $types = [
            ['Casual leave', 'CL', true, 8, false, 0],
            ['Sick leave', 'SL', true, 7, true, 7],
            ['Leave without pay', 'LWP', false, 0, false, 0],
        ];
        foreach ($types as $i => [$name, $code, $paid, $quota, $carry, $max]) {
            DB::table('leave_types')->insert([
                'name' => $name, 'code' => $code, 'is_paid' => $paid, 'annual_quota' => $quota,
                'carry_forward' => $carry, 'max_carry' => $max, 'is_active' => true, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('shifts');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'geofence_radius_m', 'weekly_off_day']);
        });
    }
};
