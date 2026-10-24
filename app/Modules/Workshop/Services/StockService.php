<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\PackageOilLitre;
use App\Modules\Workshop\Models\PlanKitItem;
use App\Modules\Workshop\Models\StockBalance;
use App\Modules\Workshop\Models\StockItem;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Support\Purchases;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consumables stock per branch at weighted-average cost: stock in from GRNs, issued
 * to jobs (one step for a plan kit), returned, adjusted. Stock never goes below zero.
 */
class StockService
{
    /**
     * Into stock at a landed unit cost; the average cost moves with it.
     */
    public function receive(int $branchId, StockItem $item, float $quantity, float $unitCost, User $user, ?int $grnLineId = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($branchId, $item, $quantity, $unitCost, $user, $grnLineId, $note) {
            $balance = $this->lockBalance($branchId, $item->id);
            $oldQty = (float) $balance->quantity;
            $newQty = round($oldQty + $quantity, 2);
            $avg = $newQty > 0 ? (max(0, $oldQty) * (float) $balance->avg_cost + $quantity * $unitCost) / $newQty : $unitCost;
            $balance->update(['quantity' => $newQty, 'avg_cost' => round($avg, 4)]);

            return StockMovement::create([
                'branch_id' => $branchId,
                'stock_item_id' => $item->id,
                'type' => StockMovement::IN,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'value' => round($quantity * $unitCost, 2),
                'goods_receipt_line_id' => $grnLineId,
                'note' => $note,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Out of stock at the average cost: issued to a job, an adjustment down, or a GRN reversed.
     *
     * @throws EstimateException when there isn't enough stock
     */
    public function take(int $branchId, StockItem $item, float $quantity, string $type, User $user, ?int $jobCardId = null, ?string $note = null, ?int $grnLineId = null): StockMovement
    {
        return DB::transaction(function () use ($branchId, $item, $quantity, $type, $user, $jobCardId, $note, $grnLineId) {
            $balance = $this->lockBalance($branchId, $item->id);
            if ($quantity > (float) $balance->quantity + 0.001) {
                throw new EstimateException(
                    "Only {$this->qty((float) $balance->quantity)} {$item->unit} of {$item->name} in stock.",
                    ['quantity' => ["Only {$this->qty((float) $balance->quantity)} {$item->unit} in stock."]],
                );
            }

            $balance->update(['quantity' => round((float) $balance->quantity - $quantity, 2)]);
            $cost = (float) $balance->avg_cost;

            return StockMovement::create([
                'branch_id' => $branchId,
                'stock_item_id' => $item->id,
                'type' => $type,
                'quantity' => -$quantity,
                'unit_cost' => $cost,
                'value' => -round($quantity * $cost, 2),
                'job_card_id' => $jobCardId,
                'goods_receipt_line_id' => $grnLineId,
                'note' => $note,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Unused stock back from a job, at the cost it went out at.
     */
    public function returnFromJob(JobCard $jobCard, StockItem $item, float $quantity, User $user, ?string $note = null): StockMovement
    {
        $issued = -(float) StockMovement::where('job_card_id', $jobCard->id)->where('stock_item_id', $item->id)
            ->whereIn('type', [StockMovement::ISSUE, StockMovement::RETURN])->sum('quantity');
        if ($quantity > $issued + 0.001) {
            throw new EstimateException("Only {$this->qty($issued)} {$item->unit} of {$item->name} was issued to this job.");
        }
        $cost = $issued > 0
            ? -(float) StockMovement::where('job_card_id', $jobCard->id)->where('stock_item_id', $item->id)
                ->whereIn('type', [StockMovement::ISSUE, StockMovement::RETURN])->sum('value') / $issued
            : 0.0;

        return DB::transaction(function () use ($jobCard, $item, $quantity, $user, $note, $cost) {
            $balance = $this->lockBalance($jobCard->branch_id, $item->id);
            $oldQty = (float) $balance->quantity;
            $newQty = round($oldQty + $quantity, 2);
            $avg = $newQty > 0 ? (max(0, $oldQty) * (float) $balance->avg_cost + $quantity * $cost) / $newQty : $cost;
            $balance->update(['quantity' => $newQty, 'avg_cost' => round($avg, 4)]);

            return StockMovement::create([
                'branch_id' => $jobCard->branch_id,
                'stock_item_id' => $item->id,
                'type' => StockMovement::RETURN,
                'quantity' => $quantity,
                'unit_cost' => round($cost, 4),
                'value' => round($quantity * $cost, 2),
                'job_card_id' => $jobCard->id,
                'note' => $note,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Physical count differs: + found, − missing/damaged, with a reason.
     */
    public function adjust(int $branchId, StockItem $item, float $change, string $reason, User $user, ?float $unitCost = null): StockMovement
    {
        if ($change < 0) {
            return $this->take($branchId, $item, -$change, StockMovement::ADJUST, $user, null, $reason);
        }

        $balance = StockBalance::firstOrNew(['branch_id' => $branchId, 'stock_item_id' => $item->id]);
        $movement = $this->receive($branchId, $item, $change, $unitCost ?? (float) ($balance->avg_cost ?? 0), $user, null, $reason);
        $movement->update(['type' => StockMovement::ADJUST]);

        return $movement;
    }

    /**
     * Suggested plan kit for a job: the plan's kit items for its car class, plus engine
     * oil (plan litres × the stock item of the job's oil grade). Quantities still issued
     * to the job are shown alongside.
     *
     * @return Collection<int, array{stock_item_id: int, name: string, unit: string, quantity: float, in_stock: float}>
     */
    public function suggestedKit(JobCard $jobCard): Collection
    {
        $jobCard->loadMissing('vehicle:id,category_id');
        $categoryId = $jobCard->vehicle?->category_id;
        $lines = collect();

        if ($jobCard->package_id) {
            $kit = PlanKitItem::with('item')
                ->where('package_id', $jobCard->package_id)
                ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $categoryId))
                ->get()
                // A class-specific quantity wins over the "every class" one
                ->sortBy(fn (PlanKitItem $k) => $k->category_id ? 0 : 1)
                ->unique('stock_item_id');
            foreach ($kit as $k) {
                if ($k->item?->is_active) {
                    $lines->push(['item' => $k->item, 'quantity' => (float) $k->quantity]);
                }
            }

            $litres = $categoryId
                ? (float) PackageOilLitre::where('package_id', $jobCard->package_id)->where('category_id', $categoryId)->value('litres')
                : 0.0;
            $oil = $jobCard->oil_type_id ? StockItem::active()->where('oil_type_id', $jobCard->oil_type_id)->first() : null;
            if ($oil && $litres > 0 && !$lines->contains(fn ($l) => $l['item']->id === $oil->id)) {
                $lines->prepend(['item' => $oil, 'quantity' => $litres]);
            }
        }

        $stock = StockBalance::where('branch_id', $jobCard->branch_id)->pluck('quantity', 'stock_item_id');

        return $lines->map(fn ($l) => [
            'stock_item_id' => $l['item']->id,
            'name' => $l['item']->name,
            'unit' => $l['item']->unit,
            'quantity' => $l['quantity'],
            'in_stock' => (float) ($stock[$l['item']->id] ?? 0),
        ])->values();
    }

    /**
     * Issue several items to a job in one step (all or nothing).
     *
     * @param array<int, array{stock_item_id: int, quantity: float|int|string}> $lines
     */
    public function issueToJob(JobCard $jobCard, array $lines, User $user, bool $isKit = false): Collection
    {
        $items = StockItem::whereIn('id', array_column($lines, 'stock_item_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($jobCard, $lines, $user, $isKit, $items) {
            $movements = collect();
            foreach ($lines as $line) {
                $qty = round((float) $line['quantity'], 2);
                $item = $items[$line['stock_item_id']] ?? null;
                if ($qty <= 0 || !$item) {
                    continue;
                }
                $movements->push($this->take($jobCard->branch_id, $item, $qty, StockMovement::ISSUE, $user, $jobCard->id, $isKit ? 'Plan kit' : null));
            }
            if ($movements->isEmpty()) {
                throw new EstimateException('Nothing to issue.');
            }
            if ($isKit && !$jobCard->kit_issued_at) {
                $jobCard->forceFill(['kit_issued_at' => now()])->save();
            }
            $this->reduceShortfall($jobCard, $movements);

            return $movements;
        });
    }

    /**
     * The technician has finished (final QC): the plan kit goes out of stock by itself — the plan
     * lines are billed from the price master, so stock must follow. Whatever is short is issued as
     * far as stock allows, kept on the job (kit_shortfall) and the coordinators are told; the bill
     * is never held up.
     *
     * @return array<int, array{stock_item_id: int, name: string, unit: string, quantity: float}> what couldn't be issued
     */
    public function autoIssueKit(JobCard $jobCard, User $user): array
    {
        if (!Purchases::enabled() || $jobCard->kit_issued_at || !$jobCard->package_id) {
            return [];
        }

        $issued = $this->issuedToJob($jobCard)->pluck('quantity', 'stock_item_id');
        $items = StockItem::whereIn('id', $this->suggestedKit($jobCard)->pluck('stock_item_id'))->get()->keyBy('id');
        $short = [];

        foreach ($this->suggestedKit($jobCard) as $line) {
            // Issued by hand already (e.g. the oil at the start of the work)
            $need = round($line['quantity'] - (float) ($issued[$line['stock_item_id']] ?? 0), 2);
            if ($need <= 0 || !($item = $items[$line['stock_item_id']] ?? null)) {
                continue;
            }
            $take = round(min($need, max(0, $line['in_stock'])), 2);
            if ($take > 0) {
                try {
                    $this->take($jobCard->branch_id, $item, $take, StockMovement::ISSUE, $user, $jobCard->id, 'Plan kit (automatic)');
                } catch (EstimateException) {
                    $take = 0.0;
                }
            }
            if ($need - $take > 0.001) {
                $short[] = ['stock_item_id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'quantity' => round($need - $take, 2)];
            }
        }

        $jobCard->forceFill(['kit_issued_at' => now(), 'kit_shortfall' => $short ?: null])->save();

        if ($short) {
            app(PartsQuoteService::class)->notifyCoordinators($jobCard, StaffAlert::kitShort($jobCard, $short));
        }

        return $short;
    }

    /**
     * Items issued by hand after the automatic kit was short come off the shortfall.
     *
     * @param Collection<int, StockMovement> $movements
     */
    protected function reduceShortfall(JobCard $jobCard, Collection $movements): void
    {
        if (!$jobCard->kit_shortfall) {
            return;
        }
        $given = $movements->groupBy('stock_item_id')->map(fn ($m) => -(float) $m->sum('quantity'));
        $left = collect($jobCard->kit_shortfall)
            ->map(fn (array $s) => ['quantity' => round($s['quantity'] - (float) ($given[$s['stock_item_id']] ?? 0), 2)] + $s)
            ->filter(fn (array $s) => $s['quantity'] > 0.001)
            ->values()->all();
        $jobCard->forceFill(['kit_shortfall' => $left ?: null])->save();
    }

    /**
     * What a job has taken from stock (net of returns), at cost.
     *
     * @return Collection<int, array{stock_item_id: int, name: string, unit: string, quantity: float, cost: float}>
     */
    public function issuedToJob(JobCard $jobCard): Collection
    {
        return StockMovement::with('item:id,name,unit')
            ->where('job_card_id', $jobCard->id)
            ->whereIn('type', [StockMovement::ISSUE, StockMovement::RETURN])
            ->get()
            ->groupBy('stock_item_id')
            ->map(fn ($moves) => [
                'stock_item_id' => $moves->first()->stock_item_id,
                'name' => $moves->first()->item?->name,
                'unit' => $moves->first()->item?->unit,
                'quantity' => round(-(float) $moves->sum('quantity'), 2),
                'cost' => round(-(float) $moves->sum('value'), 2),
            ])
            ->filter(fn ($row) => $row['quantity'] > 0)
            ->values();
    }

    protected function lockBalance(int $branchId, int $itemId): StockBalance
    {
        StockBalance::firstOrCreate(['branch_id' => $branchId, 'stock_item_id' => $itemId], ['quantity' => 0, 'avg_cost' => 0]);

        return StockBalance::where('branch_id', $branchId)->where('stock_item_id', $itemId)->lockForUpdate()->firstOrFail();
    }

    private function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 2), '0'), '.');
    }
}
