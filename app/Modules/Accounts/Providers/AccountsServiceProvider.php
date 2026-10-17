<?php

namespace App\Modules\Accounts\Providers;

use App\Modules\Accounts\Console\ResyncBooks;
use App\Modules\Accounts\Models\Expense;
use App\Modules\Accounts\Support\PostingQueue;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentPart;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\GoodsReceiptLine;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Models\SupplierPayment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccountsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ResyncBooks::class]);
        }

        $this->registerPostings();
    }

    /**
     * Whatever touches the money is posted to the books once it is committed.
     */
    protected function registerPostings(): void
    {
        $watch = function (string $model, callable $sources): void {
            $push = function ($m) use ($sources) {
                foreach ($sources($m) as [$type, $id]) {
                    PostingQueue::push($type, $id);
                }
            };
            $model::saved($push);
            $model::deleted($push);
        };

        $watch(Invoice::class, fn (Invoice $m) => [['invoice', $m->id]]);
        $watch(InvoiceItem::class, fn (InvoiceItem $m) => [['invoice', $m->invoice_id]]);
        $watch(Payment::class, fn (Payment $m) => [['payment', $m->id]]);
        $watch(PaymentPart::class, fn (PaymentPart $m) => [['payment', $m->payment_id]]);
        $watch(GoodsReceipt::class, fn (GoodsReceipt $m) => [['grn', $m->id]]);
        $watch(GoodsReceiptLine::class, fn (GoodsReceiptLine $m) => [['grn', $m->goods_receipt_id]]);
        $watch(SupplierPayment::class, fn (SupplierPayment $m) => [['supplier_payment', $m->id]]);
        $watch(StockMovement::class, fn (StockMovement $m) => [['stock_movement', $m->id]]);
        $watch(Expense::class, fn (Expense $m) => [['expense', $m->id]]);
        $watch(SalaryAdvance::class, fn (SalaryAdvance $m) => [['salary_advance', $m->id]]);
        $watch(StaffCustodyEntry::class, fn (StaffCustodyEntry $m) => [['custody_entry', $m->id]]);
        $watch(Payslip::class, fn (Payslip $m) => [['payslip', $m->id], ['payslip_payment', $m->id]]);
        $watch(PayrollRun::class, fn (PayrollRun $m) => $m->payslips()->pluck('id')->map(fn ($id) => ['payslip', (int) $id])->all());
    }
}
