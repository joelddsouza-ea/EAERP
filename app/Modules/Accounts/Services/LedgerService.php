<?php

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Models\AccountingPeriod;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Models\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry books. Every entry balances; an automatic entry belongs to what caused it
 * (source type + id) and is kept in step with it by sync():
 *  - unchanged → nothing happens;
 *  - changed in the same open month → the entry is rewritten;
 *  - changed in a later month, or the month is locked → the old entry is reversed today
 *    and a fresh one posted.
 */
class LedgerService
{
    /**
     * @param array<int, array{account: int, debit?: float, credit?: float, party?: ?array{0: string, 1: int}, memo?: ?string}> $lines
     */
    public function sync(string $sourceType, int $sourceId, string $voucherType, string $date, ?int $branchId, array $lines, ?string $narration = null, ?int $userId = null): ?JournalEntry
    {
        $lines = $this->normalise($lines);
        $current = $this->current($sourceType, $sourceId);

        if ($current && $this->sameAs($current, $date, $branchId, $lines)) {
            return $current;
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $voucherType, $date, $branchId, $lines, $narration, $userId, $current) {
            if ($current) {
                $sameOpenMonth = substr($current->entry_date->toDateString(), 0, 7) === substr($date, 0, 7)
                    && !AccountingPeriod::isLocked($current->entry_date->toDateString());

                if ($sameOpenMonth) {
                    if ($lines->isEmpty()) {
                        $current->lines()->delete();
                        $current->delete();

                        return null;
                    }
                    $current->lines()->delete();
                    $current->update([
                        'entry_date' => $date,
                        'branch_id' => $branchId,
                        'voucher_type' => $voucherType,
                        'narration' => $narration,
                        'amount' => $this->total($lines),
                    ]);
                    $this->writeLines($current, $lines);

                    return $current->fresh('lines');
                }

                $this->reverse($current, $userId);
            }

            if ($lines->isEmpty()) {
                return null;
            }

            // A change to a locked month is booked today
            $postDate = $current || AccountingPeriod::isLocked($date) ? max($date, now()->toDateString()) : $date;

            return $this->write($sourceType, $sourceId, $voucherType, $postDate, $branchId, $lines, $narration, $userId);
        });
    }

    /**
     * A hand-made voucher (journal, contra, opening balance).
     */
    public function post(string $voucherType, string $date, ?int $branchId, array $lines, ?string $narration, ?int $userId, ?string $sourceType = null, ?int $sourceId = null): JournalEntry
    {
        if (AccountingPeriod::isLocked($date)) {
            throw new LedgerException('That month is locked. Choose a date in an open month.');
        }

        $lines = $this->normalise($lines);
        if ($lines->isEmpty()) {
            throw new LedgerException('Enter at least one debit and one credit.');
        }

        return DB::transaction(fn () => $this->write($sourceType ?? 'manual', $sourceId, $voucherType, $date, $branchId, $lines, $narration, $userId));
    }

    /**
     * Undo an entry with its mirror image, dated today.
     */
    public function reverse(JournalEntry $entry, ?int $userId = null, ?string $reason = null): JournalEntry
    {
        $entry->loadMissing('lines');
        $lines = $entry->lines->map(fn (JournalLine $l) => [
            'account' => $l->account_id,
            'debit' => (float) $l->credit,
            'credit' => (float) $l->debit,
            'party' => $l->party_type ? [$l->party_type, (int) $l->party_id] : null,
            'memo' => $l->memo,
        ])->all();

        $reversal = $this->write(
            $entry->source_type,
            $entry->source_id,
            'reversal',
            max(now()->toDateString(), $entry->entry_date->toDateString()),
            $entry->branch_id,
            $this->normalise($lines),
            'Reversal of ' . $entry->number . ($reason ? " — {$reason}" : ''),
            $userId,
            $entry->id,
        );
        $entry->update(['status' => JournalEntry::REVERSED]);

        return $reversal;
    }

    public function current(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntry::POSTED)
            ->whereNull('reversal_of')
            ->with('lines')
            ->latest('id')
            ->first();
    }

    /**
     * Debit minus credit on an account (or several), optionally for a party, branch and dates.
     *
     * @param int|array<int, int> $accountIds
     */
    public function balance(int|array $accountIds, ?string $to = null, ?string $from = null, ?int $branchId = null, ?array $party = null): float
    {
        $q = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', (array) $accountIds)
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->when($party, fn ($q) => $q->where('journal_lines.party_type', $party[0])->where('journal_lines.party_id', $party[1]));

        return round((float) $q->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Rounds, nets debit against credit per account + party, drops zero lines and checks
     * the entry balances.
     *
     * @return Collection<int, array{account: int, debit: float, credit: float, party_type: ?string, party_id: ?int, memo: ?string}>
     */
    protected function normalise(array $lines): Collection
    {
        $grouped = [];
        foreach ($lines as $line) {
            $party = $line['party'] ?? null;
            $key = $line['account'] . '|' . ($party[0] ?? '') . '|' . ($party[1] ?? '');
            $grouped[$key] ??= [
                'account' => (int) $line['account'],
                'net' => 0.0,
                'party_type' => $party[0] ?? null,
                'party_id' => isset($party[1]) ? (int) $party[1] : null,
                'memo' => $line['memo'] ?? null,
            ];
            $grouped[$key]['net'] += (float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0);
        }

        $out = collect($grouped)
            ->map(function (array $l) {
                $net = round($l['net'], 2);

                return [
                    'account' => $l['account'],
                    'debit' => $net > 0 ? $net : 0.0,
                    'credit' => $net < 0 ? -$net : 0.0,
                    'party_type' => $l['party_type'],
                    'party_id' => $l['party_id'],
                    'memo' => $l['memo'],
                ];
            })
            ->filter(fn (array $l) => $l['debit'] > 0 || $l['credit'] > 0)
            ->values();

        $diff = round($out->sum('debit') - $out->sum('credit'), 2);
        if (abs($diff) >= 0.005) {
            throw new LedgerException("The entry does not balance (difference Rs {$diff}).");
        }

        return $out;
    }

    protected function write(?string $sourceType, ?int $sourceId, string $voucherType, string $date, ?int $branchId, Collection $lines, ?string $narration, ?int $userId, ?int $reversalOf = null): JournalEntry
    {
        $entry = JournalEntry::create([
            'branch_id' => $branchId,
            'number' => 'TMP-' . Str::random(16),
            'entry_date' => $date,
            'voucher_type' => $voucherType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'narration' => $narration ? Str::limit($narration, 495) : null,
            'status' => JournalEntry::POSTED,
            'reversal_of' => $reversalOf,
            'amount' => $this->total($lines),
            'created_by' => $userId,
        ]);
        $entry->update(['number' => 'JV-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT)]);
        $this->writeLines($entry, $lines);

        return $entry->fresh('lines');
    }

    protected function writeLines(JournalEntry $entry, Collection $lines): void
    {
        foreach ($lines as $l) {
            $entry->lines()->create([
                'account_id' => $l['account'],
                'party_type' => $l['party_type'],
                'party_id' => $l['party_id'],
                'debit' => $l['debit'],
                'credit' => $l['credit'],
                'memo' => $l['memo'] ? Str::limit($l['memo'], 250) : null,
            ]);
        }
    }

    protected function sameAs(JournalEntry $entry, string $date, ?int $branchId, Collection $lines): bool
    {
        if ($entry->entry_date->toDateString() !== $date || (int) $entry->branch_id !== (int) $branchId) {
            return false;
        }

        $key = fn ($account, $partyType, $partyId, $debit, $credit) => implode('|', [$account, $partyType, $partyId, number_format((float) $debit, 2, '.', ''), number_format((float) $credit, 2, '.', '')]);
        $old = $entry->lines->map(fn (JournalLine $l) => $key($l->account_id, $l->party_type, $l->party_id, $l->debit, $l->credit))->sort()->values()->all();
        $new = $lines->map(fn (array $l) => $key($l['account'], $l['party_type'], $l['party_id'], $l['debit'], $l['credit']))->sort()->values()->all();

        return $old === $new;
    }

    protected function total(Collection $lines): float
    {
        return round((float) $lines->sum('debit'), 2);
    }
}
