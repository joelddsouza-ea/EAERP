<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\GoodsReceiptLine as Line;
use App\Modules\Workshop\Models\GoodsReceiptMedia;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\SparePartQuote;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\StockItem;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Models\SupplierPayment;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Support\Purchases;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Goods received notes (purchases module): the spare parts coordinator records each
 * supplier bill or challan — job parts (the technician then checks each one), consumables
 * into stock and third-party services — with photos. Credit bills are owed to the
 * supplier until paid. A GRN can be cancelled while nothing on it has been used.
 */
class GoodsReceiptService
{
    /** A received job part can be put on a GRN from these statuses */
    public const RECEIVABLE = [SparePartRequest::APPROVED, SparePartRequest::ORDERED];

    public function __construct(
        protected PartsQuoteService $parts,
        protected StockService $stock,
        protected FileStorageService $files,
    ) {}

    /**
     * Post a GRN.
     *
     * @param array<string, mixed> $data header + lines (see PurchasesController::storeGrn)
     * @param array<int, UploadedFile> $billPhotos
     * @param array<int, array<int, UploadedFile>> $linePhotos photos per line index
     */
    public function post(array $data, int $branchId, User $user, array $billPhotos = [], array $linePhotos = []): GoodsReceipt
    {
        $supplier = $this->supplier($data);
        $noBill = $data['bill_type'] === GoodsReceipt::BILL_NONE;
        $lines = collect($data['lines'])->values();

        if ($lines->isEmpty()) {
            throw new EstimateException('Add at least one line.');
        }

        $requests = SparePartRequest::whereIn('id', $lines->pluck('spare_part_request_id')->filter())->get()->keyBy('id');
        $stockItems = StockItem::whereIn('id', $lines->pluck('stock_item_id')->filter())->get()->keyBy('id');
        $minPhotos = Purchases::minPartPhotos();

        // Check every line before anything is written
        $prepared = $lines->map(function (array $l, int $i) use ($requests, $stockItems, $noBill, $minPhotos, $linePhotos) {
            $kind = $l['kind'];
            $request = $kind === Line::JOB_PART ? ($requests[$l['spare_part_request_id'] ?? 0] ?? null) : null;
            $item = $kind === Line::STOCK ? ($stockItems[$l['stock_item_id'] ?? 0] ?? null) : null;
            $field = "lines.{$i}";

            if ($kind === Line::JOB_PART) {
                if (!$request || !in_array($request->status, self::RECEIVABLE, true)) {
                    throw new EstimateException('Only parts the customer approved (or ordered) can be received.', ["{$field}.spare_part_request_id" => ['Not an approved part.']]);
                }
                if (count($linePhotos[$i] ?? []) < $minPhotos) {
                    throw new EstimateException("Add a photo of {$request->part_name} as received.", ["{$field}.photos" => ["At least {$minPhotos} photo(s) of the part."]]);
                }
            }
            if ($kind === Line::STOCK && !$item) {
                throw new EstimateException('Choose the stock item.', ["{$field}.stock_item_id" => ['Choose the stock item.']]);
            }
            if ($kind === Line::THIRD_PARTY && empty($l['job_card_id'])) {
                throw new EstimateException('Choose the job the service was for.', ["{$field}.job_card_id" => ['Choose the job.']]);
            }

            $qty = round((float) ($l['quantity'] ?? ($request?->quantity ?? 1)), 2);
            $gstMode = $noBill ? SparePartQuote::CASH : $l['gst_mode'];
            $gstRate = $gstMode === SparePartQuote::CASH ? 0.0 : (float) ($l['gst_rate'] ?? 18);
            [$taxable, $gst, $total] = Line::amounts($qty, (float) $l['unit_cost'], $gstMode, $gstRate);

            return [
                'kind' => $kind,
                'spare_part_request_id' => $request?->id,
                'job_card_id' => $request?->job_card_id ?? ($kind === Line::THIRD_PARTY ? (int) $l['job_card_id'] : null),
                'stock_item_id' => $item?->id,
                'estimate_line_key' => $kind === Line::THIRD_PARTY ? ($l['estimate_line_key'] ?? null) : $request?->estimate_line_key,
                'description' => trim((string) ($l['description'] ?? '')) ?: ($request?->part_name ?? $item?->name ?? 'Service'),
                'part_number' => $l['part_number'] ?? $request?->part_number,
                'brand' => $l['brand'] ?? null,
                'quantity' => $qty,
                'unit' => $item?->unit ?? ($l['unit'] ?? ($request?->unit ?? 'nos')),
                'mrp' => $l['mrp'] ?? null,
                'unit_cost' => round((float) $l['unit_cost'], 2),
                'gst_mode' => $gstMode,
                'gst_rate' => $gstRate,
                'taxable_amount' => $taxable,
                'gst_amount' => $gst,
                'total' => $total,
                'verification' => $kind === Line::JOB_PART ? Line::PENDING : null,
            ];
        });

        $dupes = $prepared->pluck('spare_part_request_id')->filter()->duplicates();
        if ($dupes->isNotEmpty()) {
            throw new EstimateException('The same part is on the GRN twice.');
        }

        $grn = DB::transaction(function () use ($data, $branchId, $user, $supplier, $prepared, $stockItems, $requests, $billPhotos, $linePhotos) {
            $grn = GoodsReceipt::create([
                'branch_id' => $branchId,
                'grn_number' => GoodsReceipt::generateNumber($branchId),
                'supplier_id' => $supplier->id,
                'supplier_bill_no' => $data['supplier_bill_no'] ?? null,
                'bill_date' => $data['bill_date'] ?? now()->toDateString(),
                'bill_type' => $data['bill_type'],
                'payment_mode' => $data['payment_mode'],
                'taxable_amount' => round($prepared->sum('taxable_amount'), 2),
                'gst_amount' => round($prepared->sum('gst_amount'), 2),
                'total_amount' => round($prepared->sum('total'), 2),
                'status' => GoodsReceipt::POSTED,
                'notes' => $data['notes'] ?? null,
                'received_by' => $user->id,
            ]);

            foreach ($billPhotos as $file) {
                $this->storePhoto($grn, null, $file, $user);
            }

            $received = collect();
            foreach ($prepared as $i => $row) {
                $line = $grn->lines()->create($row);
                foreach ($linePhotos[$i] ?? [] as $file) {
                    $this->storePhoto($grn, $line, $file, $user);
                }

                if ($line->kind === Line::STOCK) {
                    $unitCost = $line->quantity > 0 ? (float) $line->total / (float) $line->quantity : 0.0;
                    $this->stock->receive($branchId, $stockItems[$line->stock_item_id], (float) $line->quantity, $unitCost, $user, $line->id, $grn->grn_number);
                }
                if ($line->kind === Line::JOB_PART) {
                    $received->push($requests[$line->spare_part_request_id]);
                }
            }

            if ($received->isNotEmpty()) {
                $this->parts->partsArrived($received, $user, $prepared->pluck('quantity', 'spare_part_request_id')->filter()->all());
            }

            return $grn;
        });

        return $grn->fresh(['lines', 'media', 'supplier']);
    }

    /**
     * The technician checks a received part in person (GRN photos are for the records, not shown to them).
     */
    public function verify(Line $line, bool $right, ?string $note, User $user): Line
    {
        $line->loadMissing(['receipt', 'jobCard', 'partRequest']);
        if ($line->kind !== Line::JOB_PART || $line->receipt->status !== GoodsReceipt::POSTED || $line->isReturned()) {
            throw new EstimateException('This part is not waiting to be checked.');
        }
        if ($line->verification !== Line::PENDING) {
            throw new EstimateException('This part has already been checked.');
        }

        $jobCard = $line->jobCard;
        $onJob = $jobCard && ($jobCard->technician_id === $user->id || $jobCard->technicians()->whereKey($user->id)->exists());
        if (!$onJob && !$user->hasPermission('workshop.job-cards.override-guards')) {
            throw new EstimateException('Only a technician on this job can check its parts.', [], 403);
        }
        if (!$right && !trim((string) $note)) {
            throw new EstimateException('Say what is wrong with the part.', ['note' => ['Say what is wrong with the part.']]);
        }

        DB::transaction(function () use ($line, $right, $note, $user) {
            $line->update([
                'verification' => $right ? Line::VERIFIED : Line::WRONG_PART,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verification_note' => $note,
            ]);

            // Wrong part: it is ordered again; the coordinator returns this one
            if (!$right && $line->partRequest) {
                $line->partRequest->update(['status' => SparePartRequest::ORDERED, 'received_at' => null, 'received_quantity' => null]);
            }
        });

        if (!$right && $jobCard) {
            $alert = StaffAlert::wrongPart($jobCard, $line->description, $user->name, $note);
            $this->parts->notifyCoordinators($jobCard, $alert);
            if ($jobCard->service_advisor_id) {
                User::find($jobCard->service_advisor_id)?->notify($alert);
            }
            app(JobLifecycleService::class)->sync($jobCard->fresh(), 'parts_received');
        }

        return $line->fresh();
    }

    /**
     * A part goes back to the supplier (wrong part, or no longer needed): its cost comes
     * off the job and, on a credit bill, off what the supplier is owed.
     */
    public function returnLine(Line $line, string $reason, User $user): Line
    {
        $line->loadMissing(['receipt', 'partRequest']);
        if ($line->receipt->status !== GoodsReceipt::POSTED || $line->isReturned()) {
            throw new EstimateException('This line cannot be returned.');
        }
        if ($line->kind === Line::STOCK) {
            throw new EstimateException('Stock goes back with a stock adjustment, or cancel the GRN.');
        }
        if ($line->kind === Line::JOB_PART && $line->verification === Line::VERIFIED) {
            throw new EstimateException('The technician accepted this part; it is on the job.');
        }

        DB::transaction(function () use ($line, $reason, $user) {
            $line->update([
                'returned_quantity' => $line->quantity,
                'returned_by' => $user->id,
                'returned_at' => now(),
                'return_reason' => $reason,
            ]);

            // Not yet checked: the part is no longer here
            if ($line->kind === Line::JOB_PART && $line->verification === Line::PENDING && $line->partRequest?->status === SparePartRequest::RECEIVED) {
                $line->partRequest->update(['status' => SparePartRequest::ORDERED, 'received_at' => null, 'received_quantity' => null]);
            }
        });

        return $line->fresh();
    }

    /**
     * Undo a GRN posted by mistake: allowed while no part on it is accepted by a technician
     * and its stock is still on hand. Parts go back to ordered, stock comes off again.
     */
    public function cancel(GoodsReceipt $grn, string $reason, User $user): GoodsReceipt
    {
        if ($grn->status !== GoodsReceipt::POSTED) {
            throw new EstimateException('This GRN is already cancelled.');
        }

        $grn->load('lines.stockItem', 'lines.partRequest');
        if ($grn->lines->contains(fn (Line $l) => $l->kind === Line::JOB_PART && $l->verification === Line::VERIFIED && !$l->isReturned())) {
            throw new EstimateException('A technician has accepted a part on this GRN. Return parts line by line instead.');
        }

        $jobIds = $grn->lines->pluck('job_card_id')->filter()->unique();

        DB::transaction(function () use ($grn, $reason, $user) {
            foreach ($grn->lines as $line) {
                if ($line->kind === Line::STOCK && $line->stockItem) {
                    $this->stock->take($grn->branch_id, $line->stockItem, (float) $line->quantity, StockMovement::GRN_CANCEL, $user, null, "{$grn->grn_number} cancelled", $line->id);
                }
                if ($line->kind === Line::JOB_PART && !$line->isReturned() && $line->partRequest?->status === SparePartRequest::RECEIVED) {
                    $line->partRequest->update(['status' => SparePartRequest::ORDERED, 'received_at' => null, 'received_quantity' => null]);
                }
            }

            $grn->update([
                'status' => GoodsReceipt::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });

        foreach (JobCard::withoutGlobalScopes()->whereIn('id', $jobIds)->get() as $jobCard) {
            app(JobLifecycleService::class)->sync($jobCard, 'parts_received');
        }

        return $grn->fresh(['lines', 'media', 'supplier']);
    }

    // ------------------------------------------------------------------ money

    /**
     * What a line costs after returns (a returned line costs nothing).
     */
    public static function lineCost(Line $line): float
    {
        $qty = (float) $line->quantity;

        return $qty > 0 ? round((float) $line->total * max(0, $qty - (float) $line->returned_quantity) / $qty, 2) : 0.0;
    }

    /**
     * Owed to each supplier: credit GRNs (after returns) less payments made.
     *
     * @return Collection<int, array{supplier_id: int, purchases: float, paid: float, due: float}>
     */
    public function supplierBalances(?int $supplierId = null): Collection
    {
        $credit = GoodsReceipt::posted()
            ->where('payment_mode', GoodsReceipt::CREDIT)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('lines')
            ->get()
            ->groupBy('supplier_id')
            ->map(fn ($grns) => round($grns->flatMap->lines->sum(fn (Line $l) => self::lineCost($l)), 2));

        $paid = SupplierPayment::when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->selectRaw('supplier_id, SUM(amount) AS paid')
            ->groupBy('supplier_id')
            ->pluck('paid', 'supplier_id');

        return $credit->keys()->merge($paid->keys())->unique()->map(fn ($id) => [
            'supplier_id' => (int) $id,
            'purchases' => (float) ($credit[$id] ?? 0),
            'paid' => round((float) ($paid[$id] ?? 0), 2),
            'due' => round((float) ($credit[$id] ?? 0) - (float) ($paid[$id] ?? 0), 2),
        ])->keyBy('supplier_id');
    }

    public function paySupplier(Supplier $supplier, array $data, int $branchId, User $user): SupplierPayment
    {
        $due = (float) ($this->supplierBalances($supplier->id)[$supplier->id]['due'] ?? 0);
        if ((float) $data['amount'] > $due + 0.001) {
            throw new EstimateException('Rs ' . number_format($due, 2) . " is all that is owed to {$supplier->name}.", ['amount' => ['More than what is owed.']]);
        }

        return SupplierPayment::create([
            'branch_id' => $branchId,
            'supplier_id' => $supplier->id,
            'amount' => round((float) $data['amount'], 2),
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?? null,
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Parts cost of a job (internal): its GRN job parts and third-party services after
     * returns, plus consumables issued from stock at average cost.
     */
    public function jobCost(JobCard $jobCard): array
    {
        $lines = Line::with('receipt:id,grn_number,status')
            ->where('job_card_id', $jobCard->id)
            ->whereHas('receipt', fn ($q) => $q->where('status', GoodsReceipt::POSTED))
            ->get();
        $parts = round($lines->where('kind', Line::JOB_PART)->sum(fn (Line $l) => self::lineCost($l)), 2);
        $services = round($lines->where('kind', Line::THIRD_PARTY)->sum(fn (Line $l) => self::lineCost($l)), 2);
        $stock = $this->stock->issuedToJob($jobCard);
        $consumables = round((float) $stock->sum('cost'), 2);

        return [
            'parts' => $parts,
            'third_party' => $services,
            'consumables' => $consumables,
            'total' => round($parts + $services + $consumables, 2),
            'lines' => $lines->map(fn (Line $l) => [
                'grn_number' => $l->receipt?->grn_number,
                'kind' => $l->kind,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'cost' => self::lineCost($l),
                'returned' => $l->isReturned(),
                'verification' => $l->verification,
            ])->values(),
            'stock' => $stock,
        ];
    }

    // ---------------------------------------------------------------- helpers

    protected function supplier(array $data): Supplier
    {
        if (!empty($data['supplier_id'])) {
            return Supplier::findOrFail($data['supplier_id']);
        }

        $name = trim((string) ($data['supplier_name'] ?? ''));
        if ($name === '') {
            throw new EstimateException('Choose the supplier.', ['supplier_id' => ['Choose the supplier.']]);
        }

        return Supplier::firstOrCreate(['name' => $name], ['phone' => $data['supplier_phone'] ?? null, 'is_active' => true]);
    }

    protected function storePhoto(GoodsReceipt $grn, ?Line $line, UploadedFile $file, User $user): GoodsReceiptMedia
    {
        $stored = $this->files->uploadPrivateMedia($file, "purchases/{$grn->id}" . ($line ? "/lines/{$line->id}" : '/bill'));

        return GoodsReceiptMedia::create([
            'goods_receipt_id' => $grn->id,
            'goods_receipt_line_id' => $line?->id,
            'disk' => $stored['disk'],
            'file_path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'uploaded_by' => $user->id,
        ]);
    }
}
