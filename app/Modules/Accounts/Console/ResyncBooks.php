<?php

namespace App\Modules\Accounts\Console;

use App\Modules\Accounts\Models\Expense;
use App\Modules\Accounts\Services\PostingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Models\SupplierPayment;
use Illuminate\Console\Command;

/**
 * Posts (or re-posts) every bill, receipt, GRN, expense and payroll entry, e.g. to put the
 * history on the books when accounting is first switched on, or after a posting failed.
 * Safe to run again: entries already in step are left alone.
 */
class ResyncBooks extends Command
{
    protected $signature = 'accounts:resync {--only= : one source type, e.g. invoice}';

    protected $description = 'Bring the account books in step with bills, receipts, GRNs, expenses and payroll';

    public function handle(PostingService $posting): int
    {
        if (!\App\Core\Support\Modules::accounts()) {
            $this->warn('The Accounts module is switched off (Settings › Modules).');

            return self::SUCCESS;
        }

        $sources = [
            'invoice' => fn () => Invoice::withoutGlobalScopes()->withTrashed()->pluck('id'),
            'payment' => fn () => Payment::withoutGlobalScopes()->withTrashed()->pluck('id'),
            'grn' => fn () => GoodsReceipt::pluck('id'),
            'supplier_payment' => fn () => SupplierPayment::pluck('id'),
            'stock_movement' => fn () => StockMovement::pluck('id'),
            'expense' => fn () => Expense::pluck('id'),
            'salary_advance' => fn () => SalaryAdvance::pluck('id'),
            'custody_entry' => fn () => StaffCustodyEntry::pluck('id'),
            'payslip' => fn () => Payslip::pluck('id'),
            'payslip_payment' => fn () => Payslip::pluck('id'),
        ];

        $only = $this->option('only');
        $failed = 0;

        foreach ($sources as $type => $ids) {
            if ($only && $only !== $type) {
                continue;
            }
            $list = $ids();
            foreach ($list as $id) {
                try {
                    $posting->syncSource($type, (int) $id);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$type} #{$id}: {$e->getMessage()}");
                }
            }
            $this->info("{$type}: {$list->count()} checked");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
