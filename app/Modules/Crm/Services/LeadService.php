<?php

namespace App\Modules\Crm\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\Creative;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadActivity;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Notifications\StaffAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leads from capture to close:
 *  - one open lead per phone number (a repeat enquiry warms the lead up instead of duplicating it);
 *  - round-robin to the branch's executives (marketing first), skipping anyone on leave or off;
 *  - first contact within the SLA, then follow-ups set by each call's outcome;
 *  - quotes from the price masters; lost with a reason (some reasons keep nurturing).
 */
class LeadService
{
    public function __construct(
        protected CrmMessageService $messages,
        protected JourneyService $journeys,
    ) {}

    // ---------------------------------------------------------------- capture

    /**
     * @return array{0: Lead, 1: bool} the lead, and whether it was new
     */
    public function capture(array $data, ?User $user = null): array
    {
        $phone = ShareLinkChannel::normalizePhone($data['phone'] ?? null) ?? preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        if (strlen($phone) < 10) {
            throw new CrmException('Enter a valid mobile number.', ['phone' => ['A 10-digit mobile number.']]);
        }

        $source = $this->source($data);
        $campaign = $this->campaign($source, $data);

        $open = Lead::open()->where('phone', $phone)->latest('id')->first();
        if ($open) {
            $fill = array_filter([
                'email' => $data['email'] ?? null,
                'car_text' => $data['car_text'] ?? null,
                'registration_no' => $data['registration_no'] ?? null,
                'requirement' => $data['requirement'] ?? null,
            ]);
            $open->fill(array_filter($fill, fn ($v, $k) => empty($open->{$k}), ARRAY_FILTER_USE_BOTH));
            $open->save();
            $this->log($open, 'enquired_again', null, 'Enquired again' . ($source ? " via {$source->name}" : '') . ($campaign ? " ({$campaign->name})" : ''), $user);
            $this->bumpScore($open, 15);

            return [$open->fresh(), false];
        }

        $customer = !empty($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : Customer::whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . substr($phone, -10)])
                ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_phone, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . substr($phone, -10)])
                ->first();
        $referrer = $this->referrer($data);

        $lead = DB::transaction(function () use ($data, $phone, $source, $campaign, $customer, $referrer, $user) {
            $lead = Lead::create([
                'branch_id' => $data['branch_id'] ?? app('branch.context')->getCurrentBranchId() ?? \App\Core\Models\Branch::active()->value('id'),
                'name' => trim((string) ($data['name'] ?? '')) ?: ($customer?->name ?? 'Enquiry ' . substr($phone, -4)),
                'phone' => $phone,
                'alt_phone' => $data['alt_phone'] ?? null,
                'email' => $data['email'] ?? $customer?->email,
                'city' => $data['city'] ?? null,
                'lead_source_id' => $source?->id,
                'campaign_id' => $campaign?->id,
                'tracking' => $data['tracking'] ?? null,
                'referred_by_customer_id' => $referrer?->id,
                'customer_id' => $customer?->id,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'car_text' => $data['car_text'] ?? null,
                'registration_no' => isset($data['registration_no']) ? strtoupper(preg_replace('/\s+/', '', $data['registration_no'])) : null,
                'make_id' => $data['make_id'] ?? null,
                'model_id' => $data['model_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'fuel_type' => $data['fuel_type'] ?? null,
                'interest' => $data['interest'] ?? null,
                'package_id' => $data['package_id'] ?? null,
                'requirement' => $data['requirement'] ?? null,
                'kind' => $data['kind'] ?? 'new_enquiry',
                'status' => Lead::NEW,
                'score' => $this->baseScore($source, $customer),
                'created_by' => $user?->id,
            ]);
            $lead->temperature = self::temperature($lead->score);
            $lead->last_activity_at = now();
            $lead->save();

            $this->log($lead, 'created', null, trim(($source?->name ?? 'Lead') . ($campaign ? " — {$campaign->name}" : '') . (!empty($data['note']) ? ": {$data['note']}" : '')), $user);

            $assignee = !empty($data['assigned_to']) ? User::find($data['assigned_to']) : $this->nextAssignee($lead->branch_id);
            if ($assignee) {
                $this->assign($lead, $assignee, null, false);
            }
            $lead->update(['first_contact_due_at' => $this->contactDue(now())]);

            return $lead;
        });

        if ($referrer) {
            \App\Modules\Crm\Models\Referral::create(['referrer_customer_id' => $referrer->id, 'lead_id' => $lead->id, 'status' => 'pending']);
        }
        if ($lead->kind === 'new_enquiry') {
            $this->journeys->enroll('new_lead', ['lead' => $lead]);
        }

        return [$lead->fresh(), true];
    }

    protected function source(array $data): ?LeadSource
    {
        if (!empty($data['lead_source_id'])) {
            return LeadSource::find($data['lead_source_id']);
        }
        if (!empty($data['source'])) {
            return LeadSource::bySlug($data['source']);
        }

        return null;
    }

    /**
     * The campaign by its platform id / name, created on first sight.
     */
    protected function campaign(?LeadSource $source, array $data): ?Campaign
    {
        if (!empty($data['campaign_id'])) {
            return Campaign::find($data['campaign_id']);
        }
        $ref = trim((string) ($data['campaign'] ?? ''));
        if (!$ref || !$source) {
            return null;
        }

        return Campaign::firstOrCreate(
            ['lead_source_id' => $source->id, 'external_ref' => mb_substr($ref, 0, 150)],
            ['name' => mb_substr($ref, 0, 150), 'is_active' => true]
        );
    }

    protected function referrer(array $data): ?Customer
    {
        if (!empty($data['referred_by_customer_id'])) {
            return Customer::find($data['referred_by_customer_id']);
        }
        if (!empty($data['referral_code'])) {
            return Customer::where('referral_code', strtoupper($data['referral_code']))->first();
        }

        return null;
    }

    // ------------------------------------------------------------ assignment

    /**
     * Next executive for the branch: marketing executives first (advisors otherwise), the
     * one assigned longest ago, skipping anyone on leave or on their weekly off today.
     */
    public function nextAssignee(?int $branchId): ?User
    {
        $users = User::active()
            ->with('roles:id,slug')
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'crm.leads.work'))
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->get()
            ->reject(fn (User $u) => $u->roles->contains('slug', Role::OWNER) || $this->awayToday($u->id));

        if ($users->isEmpty()) {
            return null;
        }

        $marketing = $users->filter(fn (User $u) => $u->roles->contains('slug', Role::MARKETING));
        $pool = $marketing->isNotEmpty() ? $marketing : $users;

        $last = Lead::whereIn('assigned_to', $pool->pluck('id'))->groupBy('assigned_to')->selectRaw('assigned_to, MAX(assigned_at) AS last_at')->pluck('last_at', 'assigned_to');

        return $pool->sortBy(fn (User $u) => [$last[$u->id] ?? '0000', $u->id])->first();
    }

    protected function awayToday(int $userId): bool
    {
        // Leave and weekly offs come from Attendance & payroll
        if (!\App\Core\Support\Modules::hr()) {
            return false;
        }

        $today = today()->toDateString();
        if (LeaveRequest::where('user_id', $userId)->where('status', LeaveRequest::APPROVED)->where('from_date', '<=', $today)->where('to_date', '>=', $today)->exists()) {
            return true;
        }
        if (in_array(AttendanceDay::where('user_id', $userId)->where('date', $today)->value('status'), [AttendanceDay::LEAVE_PAID, AttendanceDay::LEAVE_UNPAID], true)) {
            return true;
        }
        $profile = EmployeeProfile::with('branch')->where('user_id', $userId)->first();

        return $profile && $profile->attendance_required && today()->dayOfWeek === $profile->weeklyOff();
    }

    public function assign(Lead $lead, User $to, ?User $by = null, bool $log = true): Lead
    {
        $lead->update(['assigned_to' => $to->id, 'assigned_at' => now()]);
        $this->log($lead, 'assigned', null, "Assigned to {$to->name}" . ($by ? " by {$by->name}" : ''), $by);
        if ($by?->id !== $to->id) {
            $to->notify(new StaffAlert('lead_assigned', "New lead — {$lead->name}", trim($lead->carLabel() . ' · ' . ($lead->source?->name ?? 'Lead') . ($lead->requirement ? " · {$lead->requirement}" : '')), "/crm/leads/{$lead->id}"));
        }

        // Messages still waiting go to the new owner too
        \App\Modules\Workshop\Models\CustomerMessage::where('lead_id', $lead->id)->where('status', 'prepared')->update(['assigned_to' => $to->id]);

        return $lead;
    }

    /**
     * First contact due: SLA minutes from now within working hours, else from the next opening.
     */
    public function contactDue(Carbon $at): Carbon
    {
        $minutes = (int) WorkshopSettings::get('crm.sla_minutes');
        $start = Carbon::parse($at->toDateString() . ' ' . WorkshopSettings::get('crm.work_start'));
        $end = Carbon::parse($at->toDateString() . ' ' . WorkshopSettings::get('crm.work_end'));

        if ($at->lt($start)) {
            return $start->copy()->addMinutes($minutes);
        }
        if ($at->gte($end)) {
            return $start->copy()->addDay()->addMinutes($minutes);
        }

        return $at->copy()->addMinutes($minutes);
    }

    // ------------------------------------------------------------ working it

    /**
     * A call and what came of it; the next follow-up is set from the outcome.
     *
     * @param array{outcome: string, note?: ?string, call_back_at?: ?string, lost_reason_id?: ?int} $data
     */
    public function logCall(Lead $lead, array $data, User $user): Lead
    {
        $outcome = $data['outcome'];
        if (!isset(LeadActivity::OUTCOMES[$outcome])) {
            throw new CrmException('Choose what happened on the call.');
        }

        DB::transaction(function () use ($lead, $data, $outcome, $user) {
            $this->log($lead, 'call', $outcome, $data['note'] ?? null, $user);
            $lead->first_contacted_at ??= now();
            $lead->last_activity_at = now();

            switch ($outcome) {
                case 'connected':
                    $lead->unanswered_calls = 0;
                    if (in_array($lead->status, [Lead::NEW, Lead::UNREACHABLE], true)) {
                        $this->setStatus($lead, Lead::CONTACTED, $user);
                    }
                    $lead->next_follow_up_at = !empty($data['call_back_at']) ? Carbon::parse($data['call_back_at']) : now()->addDays(2)->setTime(11, 0);
                    break;

                case 'no_answer':
                case 'busy':
                    $lead->unanswered_calls++;
                    $lead->next_follow_up_at = match (true) {
                        $lead->unanswered_calls === 1 => now()->addHours(2),
                        $lead->unanswered_calls === 2 => now()->addDay()->setTime(11, 0),
                        default => now()->addDays(3)->setTime(11, 0),
                    };
                    // Three strikes: not reachable (the journey keeps messaging)
                    if ($lead->unanswered_calls >= 3 && $lead->status === Lead::NEW) {
                        $this->setStatus($lead, Lead::UNREACHABLE, $user);
                    }
                    break;

                case 'call_back':
                    if (empty($data['call_back_at'])) {
                        throw new CrmException('When should we call back?', ['call_back_at' => ['Required.']]);
                    }
                    $lead->unanswered_calls = 0;
                    $lead->next_follow_up_at = Carbon::parse($data['call_back_at']);
                    if (in_array($lead->status, [Lead::NEW, Lead::UNREACHABLE], true)) {
                        $this->setStatus($lead, Lead::CONTACTED, $user);
                    }
                    break;

                case 'not_interested':
                    $lead->save();
                    $this->markLost($lead, (int) ($data['lost_reason_id'] ?? 0), $data['note'] ?? null, $user);

                    return;

                case 'booked':
                    // The booking itself moves the lead on
                    $lead->unanswered_calls = 0;
                    break;
            }

            $lead->save();
        });

        return $lead->fresh();
    }

    public function setStatus(Lead $lead, string $status, ?User $user = null): void
    {
        if ($lead->status === $status) {
            return;
        }
        $from = $lead->status;
        $lead->status = $status;
        if (in_array($status, [Lead::WON, Lead::LOST], true)) {
            $lead->closed_at = now();
            $lead->next_follow_up_at = null;
        }
        $lead->save();
        $this->log($lead, 'status', null, (Lead::STATUSES[$from] ?? $from) . ' → ' . (Lead::STATUSES[$status] ?? $status), $user);

        if (in_array($status, [Lead::BOOKED, Lead::WON, Lead::LOST], true)) {
            $this->journeys->stopFor($lead, $status);
        }
    }

    public function markLost(Lead $lead, int $reasonId, ?string $note, ?User $user): Lead
    {
        $reason = LostReason::find($reasonId);
        if (!$reason) {
            throw new CrmException('Choose why the lead is lost.', ['lost_reason_id' => ['Required.']]);
        }

        $lead->update(['lost_reason_id' => $reason->id, 'lost_note' => $note]);
        $this->setStatus($lead, Lead::LOST, $user);
        if ($reason->nurture && !$lead->opted_out_at) {
            $this->journeys->enroll('lost_not_now', ['lead' => $lead->fresh()]);
        }

        return $lead->fresh();
    }

    public function reopen(Lead $lead, User $user): Lead
    {
        if ($lead->isOpen()) {
            return $lead;
        }
        $lead->update(['lost_reason_id' => null, 'lost_note' => null, 'closed_at' => null, 'next_follow_up_at' => now()]);
        $this->setStatus($lead, Lead::CONTACTED, $user);

        return $lead->fresh();
    }

    public function optOut(Lead $lead, ?User $user): Lead
    {
        $lead->update(['opted_out_at' => now()]);
        $lead->customer?->update(['marketing_opt_out_at' => now()]);
        $this->journeys->stopFor($lead, 'opted out');
        $this->log($lead, 'note', null, 'Asked not to receive messages', $user);

        return $lead->fresh();
    }

    /**
     * Plan prices for the lead's car type (or the range, if the type isn't known), with
     * the plan's brochure creative and a self-booking link.
     */
    public function sendQuote(Lead $lead, int $packageId, User $user): ?\App\Modules\Workshop\Models\CustomerMessage
    {
        $package = ServicePackage::with('pricing')->findOrFail($packageId);
        $categoryId = $lead->category_id ?? $lead->vehicle?->category_id;
        $oils = OilType::pluck('name', 'id');

        $rows = $package->pricing->filter(fn ($p) => !$categoryId || (int) $p->category_id === (int) $categoryId);
        if ($rows->isEmpty()) {
            throw new CrmException("{$package->name} has no prices yet (Price Masters).");
        }

        if ($categoryId) {
            $prices = $rows->sortBy('price')->map(fn ($p) => '• ' . ($oils[$p->oil_type_id] ?? 'Oil') . ': Rs ' . number_format((float) $p->price, 0))->implode("\n");
        } else {
            $cats = VehicleCategory::pluck('name', 'id');
            $prices = $rows->groupBy('category_id')->map(fn ($g, $cat) => '• ' . ($cats[$cat] ?? 'Car') . ': from Rs ' . number_format((float) $g->min('price'), 0))->implode("\n");
        }

        $points = collect($package->features ?? [])->take(6)->map(fn ($f) => '✓ ' . (is_array($f) ? ($f['name'] ?? reset($f)) : $f))->implode("\n");
        $creative = Creative::where('package_id', $package->id)->where('is_active', true)->latest('id')->first();

        $message = $this->messages->toLead($lead, 'crm.quote', (string) WorkshopSettings::get('crm.messages.plan_quote'), [
            'plan' => $package->name,
            'prices' => $prices,
            'inclusions' => $points,
            'price' => 'Rs ' . number_format((float) $rows->min('price'), 0),
        ], $creative, $user, ['package_id' => $package->id]);

        $lead->fill(['package_id' => $package->id, 'interest' => 'scheduled', 'last_activity_at' => now()]);
        $lead->first_contacted_at ??= now();
        $lead->save();
        if (in_array($lead->status, [Lead::NEW, Lead::CONTACTED, Lead::UNREACHABLE], true)) {
            $this->setStatus($lead, Lead::INTERESTED, $user);
        }
        $this->log($lead, 'quote', null, "{$package->name} prices sent", $user);

        return $message;
    }

    // ----------------------------------------------------------------- score

    protected function baseScore(?LeadSource $source, ?Customer $customer): int
    {
        $base = match ($source?->slug) {
            'walk_in' => 80,
            'referral', 'call' => 70,
            'existing' => 70,
            'whatsapp_ad' => 60,
            default => 50,
        };

        return min(100, $base + ($customer ? 10 : 0));
    }

    public function bumpScore(Lead $lead, int $by): void
    {
        $lead->score = max(0, min(100, $lead->score + $by));
        $lead->temperature = self::temperature($lead->score);
        $lead->last_activity_at = now();
        $lead->save();
    }

    public static function temperature(int $score): string
    {
        return $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
    }

    /**
     * Leads go cold when nobody touches them: −5 a week without activity.
     */
    public function decayScores(): int
    {
        $count = 0;
        Lead::open()->where('last_activity_at', '<', now()->subDays(7))->where('score', '>', 0)->lazyById()->each(function (Lead $lead) use (&$count) {
            $lead->score = max(0, $lead->score - 5);
            $lead->temperature = self::temperature($lead->score);
            $lead->last_activity_at = now();
            $lead->saveQuietly();
            $count++;
        });

        return $count;
    }

    // --------------------------------------------------------------- helpers

    public function log(Lead|int $lead, string $type, ?string $outcome, ?string $body, ?User $user = null, ?array $meta = null): LeadActivity
    {
        $leadId = $lead instanceof Lead ? $lead->id : $lead;

        return LeadActivity::create(['lead_id' => $leadId, 'type' => $type, 'outcome' => $outcome, 'body' => $body, 'meta' => $meta, 'user_id' => $user?->id]);
    }

    /**
     * Follow-ups due for a user (or everyone): overdue first, hot before cold.
     *
     * @return Collection<int, Lead>
     */
    public function dueFollowUps(?int $userId, ?string $until = null): Collection
    {
        $until ??= today()->endOfDay()->toDateTimeString();

        return Lead::open()
            ->with(['source:id,name', 'assignee:id,name'])
            ->when($userId, fn ($q) => $q->where('assigned_to', $userId))
            ->where(fn ($q) => $q->where('next_follow_up_at', '<=', $until)
                ->orWhere(fn ($q) => $q->whereNull('first_contacted_at')->where('status', Lead::NEW)))
            ->orderByRaw("FIELD(temperature, 'hot', 'warm', 'cold')")
            ->orderBy('next_follow_up_at')
            ->limit(200)
            ->get();
    }

    public function present(Lead $l, bool $full = false): array
    {
        $l->loadMissing(['source:id,name,slug', 'campaign:id,name', 'assignee:id,name', 'lostReason:id,name', 'package:id,name', 'category:id,name', 'customer:id,name']);
        $overdue = $l->isOpen() && (
            ($l->next_follow_up_at && $l->next_follow_up_at->isPast())
            || (!$l->first_contacted_at && $l->first_contact_due_at && $l->first_contact_due_at->isPast())
        );

        $row = [
            'id' => $l->id,
            'name' => $l->name,
            'phone' => $l->phone,
            'branch_id' => $l->branch_id,
            'source' => $l->source?->name,
            'source_slug' => $l->source?->slug,
            'campaign' => $l->campaign?->name,
            'car' => $l->carLabel(),
            'interest' => $l->interest,
            'interest_label' => Lead::INTERESTS[$l->interest] ?? null,
            'package' => $l->package?->name,
            'package_id' => $l->package_id,
            'category_id' => $l->category_id,
            'category' => $l->category?->name,
            'kind' => $l->kind,
            'kind_label' => Lead::KINDS[$l->kind] ?? $l->kind,
            'status' => $l->status,
            'status_label' => Lead::STATUSES[$l->status] ?? $l->status,
            'temperature' => $l->temperature,
            'score' => $l->score,
            'assigned_to' => $l->assigned_to,
            'assignee' => $l->assignee?->name,
            'next_follow_up_at' => $l->next_follow_up_at?->toIso8601String(),
            'first_contact_due_at' => $l->first_contact_due_at?->toIso8601String(),
            'first_contacted_at' => $l->first_contacted_at?->toIso8601String(),
            'overdue' => $overdue,
            'customer_id' => $l->customer_id,
            'is_customer' => (bool) $l->customer_id,
            'requirement' => $l->requirement,
            'lost_reason' => $l->lostReason?->name,
            'won_revenue' => $l->won_revenue,
            'opted_out' => (bool) $l->opted_out_at,
            'created_at' => $l->created_at?->toIso8601String(),
        ];

        if ($full) {
            $l->loadMissing(['activities.user:id,name', 'bookings', 'referrer:id,name', 'vehicle', 'make:id,name', 'model:id,name']);
            $row += [
                'email' => $l->email,
                'alt_phone' => $l->alt_phone,
                'city' => $l->city,
                'car_text' => $l->car_text,
                'registration_no' => $l->registration_no,
                'make_id' => $l->make_id,
                'model_id' => $l->model_id,
                'fuel_type' => $l->fuel_type,
                'vehicle_id' => $l->vehicle_id,
                'referrer' => $l->referrer?->name,
                'tracking' => $l->tracking,
                'lost_note' => $l->lost_note,
                'unanswered_calls' => $l->unanswered_calls,
                'call_url' => 'tel:+' . ltrim($l->phone, '+'),
                'whatsapp_url' => 'https://wa.me/' . $l->phone,
                'activities' => $l->activities->take(100)->map(fn (LeadActivity $a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'outcome' => $a->outcome,
                    'outcome_label' => $a->outcome ? (LeadActivity::OUTCOMES[$a->outcome] ?? $a->outcome) : null,
                    'body' => $a->body,
                    'by' => $a->user?->name,
                    'at' => $a->created_at?->toIso8601String(),
                ])->values(),
                'bookings' => $l->bookings->sortByDesc('id')->map(fn ($b) => app(BookingService::class)->present($b))->values(),
            ];
        }

        return $row;
    }
}
