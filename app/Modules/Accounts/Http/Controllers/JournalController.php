<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Models\JournalLine;
use App\Modules\Accounts\Services\AccountsReportService;
use App\Modules\Accounts\Services\LedgerException;
use App\Modules\Accounts\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hand-made vouchers: journal, contra (cash ↔ bank) and opening balances. Automatic
 * entries can't be edited here; they follow their bill / receipt / GRN.
 */
class JournalController extends ApiController
{
    public function __construct(
        protected LedgerService $ledger,
        protected AccountsReportService $reports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voucher_type' => 'required|in:journal,contra,opening',
            'entry_date' => 'required|date',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'narration' => 'required|string|max:500',
            'lines' => 'required|array|min:2|max:50',
            'lines.*.account_id' => 'required|integer|exists:accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.party_type' => 'nullable|in:customer,supplier,user',
            'lines.*.party_id' => 'nullable|integer|required_with:lines.*.party_type',
            'lines.*.memo' => 'nullable|string|max:250',
        ]);

        $lines = collect($data['lines'])->map(fn ($l) => [
            'account' => (int) $l['account_id'],
            'debit' => (float) ($l['debit'] ?? 0),
            'credit' => (float) ($l['credit'] ?? 0),
            'party' => !empty($l['party_type']) ? [$l['party_type'], (int) $l['party_id']] : null,
            'memo' => $l['memo'] ?? null,
        ])->all();

        if (collect($lines)->sum('debit') <= 0) {
            throw new LedgerException('Enter the amounts.');
        }

        $entry = $this->ledger->post(
            $data['voucher_type'],
            $data['entry_date'],
            $data['branch_id'] ?? app('branch.context')->getCurrentBranchId(),
            $lines,
            $data['narration'],
            $request->user()->id,
        );

        return $this->created($this->reports->presentEntry($entry), "Voucher {$entry->number} posted");
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        return $this->success($this->reports->presentEntry($entry));
    }

    /**
     * Only hand-made vouchers are reversed here.
     */
    public function reverse(Request $request, JournalEntry $entry): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        if ($entry->source_type !== 'manual' || $entry->status !== JournalEntry::POSTED || $entry->reversal_of) {
            throw new LedgerException('Only a hand-made voucher can be reversed here. Automatic entries follow their bill, receipt or GRN.');
        }

        $reversal = $this->ledger->reverse($entry, $request->user()->id, $data['reason']);

        return $this->success($this->reports->presentEntry($reversal), "Reversed by {$reversal->number}");
    }

    /**
     * Parties for the voucher form.
     */
    public function parties(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $q = (string) $request->query('q', '');

        $rows = match ($type) {
            JournalLine::PARTY_CUSTOMER => \App\Modules\Workshop\Models\Customer::where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->limit(20)->get(['id', 'name']),
            JournalLine::PARTY_SUPPLIER => \App\Modules\Workshop\Models\Supplier::where('name', 'like', "%{$q}%")->limit(20)->get(['id', 'name']),
            JournalLine::PARTY_USER => \App\Core\Models\User::where('name', 'like', "%{$q}%")->limit(20)->get(['id', 'name']),
            default => collect(),
        };

        return $this->success($rows);
    }
}
