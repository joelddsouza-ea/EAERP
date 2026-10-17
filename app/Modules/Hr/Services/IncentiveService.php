<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Hr\Models\IncentiveRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automatic incentives from the month's bills (tax invoices and paid proformas closed
 * without one). On each bill, technicians on the job share a technician rule evenly, the
 * job's advisor gets an advisor rule; any other role in a rule (e.g. manager) earns it on
 * every bill of their branches. Values before GST.
 */
class IncentiveService
{
    public const JOB_ROLES_TECH = [Role::TECHNICIAN, Role::BODY_SHOP_TECHNICIAN];
    public const JOB_ROLES_ADVISOR = [Role::SERVICE_ADVISOR, Role::BODY_SHOP_ADVISOR];

    /**
     * @return array<int, array<int, array{rule_id: int, label: string, amount: float}>> by user id
     */
    public function forPeriod(string $period): array
    {
        $rules = IncentiveRule::where('is_active', true)->get();
        if ($rules->isEmpty()) {
            return [];
        }

        $bills = $this->bills($period);
        $roleUsers = $this->usersByRole($rules->flatMap(fn (IncentiveRule $r) => $r->roles)->unique()->values()->all());

        // [user][rule] => ['amount' => , 'billing' => , 'count' =>]
        $earned = [];
        foreach ($bills as $bill) {
            foreach ($rules as $rule) {
                $value = $this->value($rule, $bill);
                $recipients = $this->recipients($rule, $bill, $roleUsers);
                if (!$recipients) {
                    continue;
                }
                $share = 1 / count($recipients);
                foreach ($recipients as $userId) {
                    $row = &$earned[$userId][$rule->id];
                    $row['amount'] = ($row['amount'] ?? 0) + $value * $share;
                    $row['billing'] = ($row['billing'] ?? 0) + $bill['taxable'] * $share;
                    $row['count'] = ($row['count'] ?? 0) + 1;
                    unset($row);
                }
            }
        }

        $out = [];
        foreach ($earned as $userId => $byRule) {
            foreach ($byRule as $ruleId => $row) {
                $rule = $rules->firstWhere('id', $ruleId);
                $amount = round($row['amount'], 2);
                if ($amount <= 0 || $row['billing'] < $rule->min_monthly_billing) {
                    continue;
                }
                $out[$userId][] = [
                    'rule_id' => $rule->id,
                    'label' => "{$rule->name} ({$row['count']} bill" . ($row['count'] === 1 ? '' : 's') . ')',
                    'amount' => $amount,
                ];
            }
        }

        return $out;
    }

    protected function value(IncentiveRule $rule, array $bill): float
    {
        return match ($rule->basis) {
            'per_job_delivered' => $rule->value,
            'pct_labour' => $bill['labour'] * $rule->value / 100,
            'pct_parts' => $bill['parts'] * $rule->value / 100,
            'pct_bill' => $bill['taxable'] * $rule->value / 100,
            'per_plan_sold' => $bill['package_id'] && (!$rule->package_id || $rule->package_id === $bill['package_id']) ? $rule->value : 0.0,
            default => 0.0,
        };
    }

    /**
     * @return array<int, int> user ids
     */
    protected function recipients(IncentiveRule $rule, array $bill, array $roleUsers): array
    {
        $ids = [];
        foreach ($rule->roles as $role) {
            if (in_array($role, self::JOB_ROLES_TECH, true)) {
                $ids = [...$ids, ...array_values(array_intersect($bill['technicians'], $roleUsers[$role] ?? []))];
            } elseif (in_array($role, self::JOB_ROLES_ADVISOR, true)) {
                if ($bill['advisor'] && in_array($bill['advisor'], $roleUsers[$role] ?? [], true)) {
                    $ids[] = $bill['advisor'];
                }
            } else {
                foreach ($roleUsers[$role] ?? [] as $userId) {
                    if (in_array($bill['branch_id'], $roleUsers['_branches'][$userId] ?? [], true) || in_array($userId, $roleUsers['_all_branches'] ?? [], true)) {
                        $ids[] = $userId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The month's bills with what each is worth and who worked on it.
     *
     * @return Collection<int, array>
     */
    public function bills(string $period): Collection
    {
        $from = $period . '-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();

        $bills = Invoice::withoutGlobalScopes()
            ->with('items')
            ->whereNotNull('job_card_id')
            ->whereBetween('invoice_date', [$from, $to])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', Invoice::KIND_TAX)->whereIn('status', [Invoice::ISSUED, Invoice::PARTIAL, Invoice::PAID]))
                ->orWhere(fn ($q) => $q->where('kind', Invoice::KIND_PROFORMA)->where('status', Invoice::CLOSED)))
            ->get();

        $jobIds = $bills->pluck('job_card_id')->unique()->values();
        $jobs = DB::table('job_cards')->whereIn('id', $jobIds)->get(['id', 'technician_id', 'service_advisor_id', 'package_id'])->keyBy('id');
        $techs = DB::table('job_card_technicians')->whereIn('job_card_id', $jobIds)->get(['job_card_id', 'user_id'])->groupBy('job_card_id');

        return $bills->map(function (Invoice $bill) use ($jobs, $techs) {
            $job = $jobs[$bill->job_card_id] ?? null;
            $technicians = collect($techs[$bill->job_card_id] ?? [])->pluck('user_id')->map(fn ($id) => (int) $id);
            if ($job?->technician_id) {
                $technicians->push((int) $job->technician_id);
            }
            $parts = (float) $bill->items->where('type', 'part')->sum('taxable_amount');

            return [
                'id' => $bill->id,
                'branch_id' => (int) $bill->branch_id,
                'taxable' => (float) $bill->taxable_amount,
                'parts' => $parts,
                'labour' => (float) $bill->taxable_amount - $parts,
                'package_id' => $job?->package_id ? (int) $job->package_id : null,
                'technicians' => $technicians->unique()->values()->all(),
                'advisor' => $job?->service_advisor_id ? (int) $job->service_advisor_id : null,
            ];
        });
    }

    /**
     * @return array<string, mixed> role slug => user ids, plus each user's branches
     */
    protected function usersByRole(array $roles): array
    {
        $users = User::active()->with(['roles:id,slug', 'branches:id'])
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', $roles))
            ->get();

        $out = ['_branches' => [], '_all_branches' => []];
        foreach ($users as $user) {
            foreach ($user->roles as $role) {
                $out[$role->slug][] = $user->id;
            }
            $out['_branches'][$user->id] = $user->branches->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($user->roles->contains('slug', Role::OWNER)) {
                $out['_all_branches'][] = $user->id;
            }
        }

        return $out;
    }
}
