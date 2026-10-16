<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PlanKitItem;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\StockBalance;
use App\Modules\Workshop\Models\StockItem;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Services\EstimateException;
use App\Modules\Workshop\Services\GoodsReceiptService;
use App\Modules\Workshop\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Consumables stock (purchases module): on hand per branch, movements, adjustments,
 * the stock-item and plan-kit masters, and issuing to / returning from a job.
 */
class StockController extends ApiController
{
    public function __construct(
        protected StockService $stock,
        protected GoodsReceiptService $grns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $balances = StockBalance::where('branch_id', $branchId)->get()->keyBy('stock_item_id');

        $items = StockItem::orderBy('category')->orderBy('name')->get()->map(function (StockItem $i) use ($balances) {
            $b = $balances[$i->id] ?? null;
            $qty = (float) ($b?->quantity ?? 0);

            return [
                'id' => $i->id,
                'name' => $i->name,
                'category' => $i->category,
                'unit' => $i->unit,
                'is_active' => $i->is_active,
                'quantity' => $qty,
                'avg_cost' => round((float) ($b?->avg_cost ?? 0), 2),
                'value' => round($qty * (float) ($b?->avg_cost ?? 0), 2),
                'reorder_level' => (float) $i->reorder_level,
                'low' => $i->is_active && (float) $i->reorder_level > 0 && $qty <= (float) $i->reorder_level,
            ];
        });

        return $this->success([
            'items' => $items->values(),
            'total_value' => round((float) $items->sum('value'), 2),
            'low_count' => $items->where('low', true)->count(),
        ]);
    }

    public function movements(Request $request, StockItem $stockItem): JsonResponse
    {
        $moves = StockMovement::with(['jobCard:id,job_number', 'createdBy:id,name'])
            ->where('branch_id', $this->branchId($request))
            ->where('stock_item_id', $stockItem->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantity' => (float) $m->quantity,
                'unit_cost' => round((float) $m->unit_cost, 2),
                'value' => (float) $m->value,
                'job_number' => $m->jobCard?->job_number,
                'note' => $m->note,
                'by' => $m->createdBy?->name,
                'at' => $m->created_at?->toIso8601String(),
            ]);

        return $this->success(['item' => $stockItem->only(['id', 'name', 'unit']), 'movements' => $moves->values()]);
    }

    public function adjust(Request $request, StockItem $stockItem): JsonResponse
    {
        $data = $request->validate([
            'change' => 'required|numeric|not_in:0|min:-99999|max:99999',
            'reason' => 'required|string|min:3|max:255',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $this->stock->adjust($this->branchId($request), $stockItem, (float) $data['change'], $data['reason'], $request->user(), isset($data['unit_cost']) ? (float) $data['unit_cost'] : null);

        return $this->index($request);
    }

    // ---------------------------------------------------------- masters

    public function items(): JsonResponse
    {
        return $this->success([
            'items' => StockItem::with('oilType:id,name')->orderBy('category')->orderBy('name')->get(),
            'categories' => StockItem::CATEGORIES,
            'units' => StockItem::UNITS,
            'oil_types' => OilType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $item = StockItem::create($request->validate($this->itemRules()));

        return $this->created($item, 'Stock item added');
    }

    public function updateItem(Request $request, StockItem $stockItem): JsonResponse
    {
        $stockItem->update($request->validate($this->itemRules(false)));

        return $this->success($stockItem->fresh(), 'Stock item saved');
    }

    /**
     * Plan kits: what each plan uses from stock per car class (engine oil comes from the oil litres).
     */
    public function kits(): JsonResponse
    {
        return $this->success([
            'kits' => PlanKitItem::with('item:id,name,unit')->get()->map(fn (PlanKitItem $k) => [
                'id' => $k->id,
                'package_id' => $k->package_id,
                'category_id' => $k->category_id,
                'stock_item_id' => $k->stock_item_id,
                'item' => $k->item?->name,
                'unit' => $k->item?->unit,
                'quantity' => (float) $k->quantity,
            ])->values(),
            'packages' => ServicePackage::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'categories' => VehicleCategory::orderBy('id')->get(['id', 'name']),
            'items' => StockItem::active()->where('category', '!=', 'oil')->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    /**
     * Replace one plan's kit (all classes) with the lines sent.
     */
    public function saveKit(Request $request, ServicePackage $package): JsonResponse
    {
        $data = $request->validate([
            'lines' => 'present|array|max:100',
            'lines.*.category_id' => ['nullable', Rule::exists('vehicle_categories', 'id')],
            'lines.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => 'required|numeric|min:0.01|max:9999',
        ]);

        DB::transaction(function () use ($package, $data) {
            PlanKitItem::where('package_id', $package->id)->delete();
            foreach (collect($data['lines'])->unique(fn ($l) => ($l['category_id'] ?? 'all') . ':' . $l['stock_item_id']) as $line) {
                PlanKitItem::create([
                    'package_id' => $package->id,
                    'category_id' => $line['category_id'] ?? null,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                ]);
            }
        });

        return $this->kits();
    }

    // ------------------------------------------------------------- jobs

    /**
     * A job's stock: the suggested plan kit, what has been issued, and (with permission) its parts cost.
     */
    public function job(Request $request, JobCard $jobCard): JsonResponse
    {
        return $this->success($this->jobPayload($request, $jobCard));
    }

    public function issue(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'kit' => 'sometimes|boolean',
            'lines' => 'required|array|min:1|max:50',
            'lines.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => 'required|numeric|min:0|max:9999',
        ]);
        if ($jobCard->isClosed()) {
            throw new EstimateException('The job is closed.');
        }

        $this->stock->issueToJob($jobCard, $data['lines'], $request->user(), (bool) ($data['kit'] ?? false));

        return $this->success($this->jobPayload($request, $jobCard->fresh()), ($data['kit'] ?? false) ? 'Plan kit issued' : 'Issued');
    }

    public function returnToStock(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'stock_item_id' => ['required', Rule::exists('stock_items', 'id')],
            'quantity' => 'required|numeric|min:0.01|max:9999',
            'note' => 'nullable|string|max:255',
        ]);

        $this->stock->returnFromJob($jobCard, StockItem::findOrFail($data['stock_item_id']), (float) $data['quantity'], $request->user(), $data['note'] ?? null);

        return $this->success($this->jobPayload($request, $jobCard->fresh()), 'Returned to stock');
    }

    private function jobPayload(Request $request, JobCard $jobCard): array
    {
        return [
            'kit_issued_at' => $jobCard->kit_issued_at?->toIso8601String(),
            'suggested_kit' => $this->stock->suggestedKit($jobCard),
            'issued' => $this->stock->issuedToJob($jobCard),
            'items' => StockItem::active()->orderBy('name')->get(['id', 'name', 'unit', 'category']),
            'cost' => $request->user()->hasPermission('purchases.job-cost.view') ? $this->grns->jobCost($jobCard) : null,
        ];
    }

    private function itemRules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:255",
            'category' => [$req, Rule::in(StockItem::CATEGORIES)],
            'unit' => [$req, Rule::in(StockItem::UNITS)],
            'oil_type_id' => ['nullable', Rule::exists('oil_types', 'id')],
            'hsn_code' => 'nullable|string|max:20',
            'gst_rate' => 'nullable|numeric|in:0,5,12,18,28',
            'reorder_level' => 'nullable|numeric|min:0|max:99999',
            'is_active' => 'sometimes|boolean',
        ];
    }

    private function branchId(Request $request): int
    {
        $branchId = app('branch.context')->getCurrentBranchId() ?? $request->user()->primaryBranch()?->id;
        if (!$branchId) {
            throw new EstimateException('Choose a branch first.');
        }

        return (int) $branchId;
    }
}
