<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\CampaignSpend;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Referral;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where leads come from and what they turn into: funnel by source / campaign /
 * executive, cost per lead and booking, ROI, speed to lead, lost reasons, retention.
 */
class CrmInsightsService
{
    public function summary(string $from, string $to, ?int $branchId = null): array
    {
        $leads = Lead::query()
            ->with(['source:id,name', 'campaign:id,name', 'assignee:id,name', 'lostReason:id,name'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('kind', 'new_enquiry')
            ->get();

        $booked = Booking::whereIn('lead_id', $leads->pluck('id'))->whereIn('status', Booking::HOLDING)->pluck('lead_id')->unique();
        $arrived = Booking::whereIn('lead_id', $leads->pluck('id'))->where('status', Booking::CONVERTED)->pluck('lead_id')->unique();

        $funnel = fn (Collection $set) => [
            'leads' => $set->count(),
            'contacted' => $set->whereNotNull('first_contacted_at')->count(),
            'booked' => $set->whereIn('id', $booked)->count(),
            'arrived' => $set->whereIn('id', $arrived)->count(),
            'won' => $set->where('status', Lead::WON)->count(),
            'revenue' => round((float) $set->sum('won_revenue'), 2),
        ];

        $spends = CampaignSpend::with('campaign:id,lead_source_id,name')
            ->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])
            ->get();
        $spendBySource = $spends->groupBy(fn ($s) => $s->campaign?->lead_source_id)->map->sum('amount');
        $spendByCampaign = $spends->groupBy('campaign_id')->map->sum('amount');

        $withCost = function (array $row, float $spend) {
            return [
                ...$row,
                'spend' => round($spend, 2),
                'cost_per_lead' => $spend && $row['leads'] ? round($spend / $row['leads'], 0) : null,
                'cost_per_booking' => $spend && $row['booked'] ? round($spend / $row['booked'], 0) : null,
                'roi' => $spend ? round(($row['revenue'] - $spend) / $spend * 100, 0) : null,
                'conversion' => $row['leads'] ? round($row['won'] / $row['leads'] * 100, 1) : 0,
            ];
        };

        $bySource = $leads->groupBy('lead_source_id')->map(fn ($set, $id) => $withCost(['name' => $set->first()->source?->name ?? 'Unknown', ...$funnel($set)], (float) ($spendBySource[$id] ?? 0)))->sortByDesc('leads')->values();
        $byCampaign = $leads->whereNotNull('campaign_id')->groupBy('campaign_id')->map(fn ($set, $id) => $withCost(['name' => $set->first()->campaign?->name, 'source' => $set->first()->source?->name, ...$funnel($set)], (float) ($spendByCampaign[$id] ?? 0)))->sortByDesc('leads')->values();
        $byExecutive = $leads->groupBy('assigned_to')->map(fn ($set) => ['name' => $set->first()->assignee?->name ?? 'Unassigned', ...$funnel($set), 'conversion' => $set->count() ? round($set->where('status', Lead::WON)->count() / $set->count() * 100, 1) : 0])->sortByDesc('won')->values();

        // Speed to lead: minutes from capture to first contact
        $minutes = $leads->whereNotNull('first_contacted_at')->map(fn ($l) => $l->created_at->diffInMinutes($l->first_contacted_at, true))->sort()->values();
        $median = $minutes->isEmpty() ? null : (int) round($minutes[intdiv($minutes->count(), 2)]);
        $slaMissed = $leads->filter(fn ($l) => $l->first_contact_due_at && (($l->first_contacted_at && $l->first_contacted_at->gt($l->first_contact_due_at)) || (!$l->first_contacted_at && $l->first_contact_due_at->isPast())))->count();

        $lost = $leads->where('status', Lead::LOST)->groupBy(fn ($l) => $l->lostReason?->name ?? 'No reason')->map->count()->sortDesc();

        $totalSpend = (float) $spends->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'funnel' => $withCost($funnel($leads), $totalSpend),
            'by_source' => $bySource,
            'by_campaign' => $byCampaign,
            'by_executive' => $byExecutive,
            'speed' => ['median_minutes' => $median, 'sla_missed' => $slaMissed],
            'lost_reasons' => $lost->map(fn ($n, $name) => ['name' => $name, 'count' => $n])->values(),
            'retention' => $this->retention($from, $to, $branchId),
        ];
    }

    protected function retention(string $from, string $to, ?int $branchId): array
    {
        $monthStart = Carbon::parse($from)->startOfMonth();
        $monthEnd = Carbon::parse($to)->endOfMonth();
        $due = Vehicle::whereBetween('next_service_due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->pluck('id');
        $dueBooked = $due->isEmpty() ? 0 : JobCard::withoutGlobalScopes()->whereIn('vehicle_id', $due)->where('created_at', '>=', $monthStart->copy()->subMonth())->distinct('vehicle_id')->count('vehicle_id');

        $jobs = JobCard::withoutGlobalScopes()
            ->where('status', 'delivered')
            ->whereBetween('updated_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'customer_id']);
        $repeat = $jobs->isEmpty() ? 0 : JobCard::withoutGlobalScopes()
            ->whereIn('customer_id', $jobs->pluck('customer_id'))
            ->where('status', 'delivered')
            ->select('customer_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('customer_id')
            ->having('n', '>', 1)
            ->get()->count();

        $ratings = Feedback::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        return [
            'service_due' => $due->count(),
            'service_due_booked' => $dueBooked,
            'delivered' => $jobs->count(),
            'repeat_customers' => $repeat,
            'repeat_pct' => $jobs->pluck('customer_id')->unique()->count() ? round($repeat / $jobs->pluck('customer_id')->unique()->count() * 100, 1) : 0,
            'feedback_count' => (clone $ratings)->count(),
            'avg_rating' => round((float) (clone $ratings)->avg('rating'), 1),
            'low_ratings_open' => (clone $ratings)->where('rating', '<=', 3)->whereNull('resolved_at')->count(),
            'referrals' => Referral::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->count(),
            'referrals_rewarded' => Referral::where('status', Referral::REWARDED)->whereBetween('rewarded_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->count(),
        ];
    }

    /**
     * Owner dashboard tiles.
     */
    public function tiles(?int $branchId = null): array
    {
        $scope = fn ($q) => $q->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $monthLeads = Lead::where('kind', 'new_enquiry')->where('created_at', '>=', today()->startOfMonth())->tap($scope);

        return [
            'new_leads_today' => Lead::whereDate('created_at', today())->tap($scope)->count(),
            'follow_ups_overdue' => Lead::open()->where('next_follow_up_at', '<', now())->tap($scope)->count(),
            'not_contacted' => Lead::where('status', Lead::NEW)->whereNull('first_contacted_at')->tap($scope)->count(),
            'bookings_today' => Booking::whereDate('booking_date', today())->whereIn('status', [Booking::BOOKED, Booking::CONFIRMED, Booking::ARRIVED, Booking::CONVERTED])->tap($scope)->count(),
            'month_leads' => (clone $monthLeads)->count(),
            'month_won' => (clone $monthLeads)->where('status', Lead::WON)->count(),
        ];
    }
}
