<?php

namespace App\Modules\Accounts\Support;

use Illuminate\Support\Facades\DB;

/**
 * The seeded chart of accounts. System heads are found by their sub_type (never by
 * name or code, which the owner may change); plain expense heads are offered on the
 * expense form.
 */
class ChartOfAccounts
{
    // System heads
    public const CASH = 'cash';
    public const BANK = 'bank';
    public const DEBTORS = 'receivable';
    public const CREDITORS = 'payable';
    public const STAFF_CUSTODY = 'staff_custody';
    public const SALARY_ADVANCES = 'salary_advances';
    public const STOCK = 'stock';
    public const INPUT_CGST = 'gst_input_cgst';
    public const INPUT_SGST = 'gst_input_sgst';
    public const INPUT_IGST = 'gst_input_igst';
    public const OUTPUT_CGST = 'gst_output_cgst';
    public const OUTPUT_SGST = 'gst_output_sgst';
    public const OUTPUT_IGST = 'gst_output_igst';
    public const SALARY_PAYABLE = 'salary_payable';
    public const PF_PAYABLE = 'pf_payable';
    public const ESI_PAYABLE = 'esi_payable';
    public const PT_PAYABLE = 'pt_payable';
    public const CAPITAL = 'capital';
    public const DRAWINGS = 'drawings';
    public const OPENING = 'opening_balance';
    public const SALES_SERVICES = 'sales_services';
    public const SALES_PARTS = 'sales_parts';
    public const SALES_OTHER = 'sales_other';
    public const STAFF_RECOVERIES = 'staff_recoveries';
    public const ROUND_OFF = 'round_off';
    public const PURCHASE_PARTS = 'purchase_parts';
    public const THIRD_PARTY = 'third_party_services';
    public const CONSUMABLES_USED = 'consumables_used';
    public const STOCK_ADJUSTMENT = 'stock_adjustment';
    public const SALARIES = 'salaries';
    public const OVERTIME = 'overtime';
    public const INCENTIVES = 'incentives';
    public const EMPLOYER_CONTRIBUTIONS = 'employer_contributions';
    public const STAFF_REIMBURSEMENTS = 'staff_reimbursements';

    /**
     * [code, name, type, sub_type, group, expense head]
     */
    public const HEADS = [
        ['1100', 'Cash in hand', 'asset', self::CASH, 'Cash & bank', false],
        ['1200', 'Bank – current account', 'asset', self::BANK, 'Cash & bank', false],
        ['1300', 'Sundry debtors (customers)', 'asset', self::DEBTORS, 'Receivables', false],
        ['1310', 'Customer money held by staff', 'asset', self::STAFF_CUSTODY, 'Receivables', false],
        ['1320', 'Salary advances', 'asset', self::SALARY_ADVANCES, 'Loans & advances', false],
        ['1400', 'Stock – oil & consumables', 'asset', self::STOCK, 'Stock', false],
        ['1500', 'Input CGST', 'asset', self::INPUT_CGST, 'GST input', false],
        ['1501', 'Input SGST', 'asset', self::INPUT_SGST, 'GST input', false],
        ['1502', 'Input IGST', 'asset', self::INPUT_IGST, 'GST input', false],
        ['1600', 'Tools & equipment', 'asset', null, 'Fixed assets', false],
        ['1700', 'Deposits', 'asset', null, 'Loans & advances', false],

        ['2100', 'Sundry creditors (suppliers)', 'liability', self::CREDITORS, 'Payables', false],
        ['2200', 'Output CGST', 'liability', self::OUTPUT_CGST, 'GST payable', false],
        ['2201', 'Output SGST', 'liability', self::OUTPUT_SGST, 'GST payable', false],
        ['2202', 'Output IGST', 'liability', self::OUTPUT_IGST, 'GST payable', false],
        ['2300', 'Salary payable', 'liability', self::SALARY_PAYABLE, 'Staff dues', false],
        ['2310', 'PF payable', 'liability', self::PF_PAYABLE, 'Staff dues', false],
        ['2320', 'ESI payable', 'liability', self::ESI_PAYABLE, 'Staff dues', false],
        ['2330', 'Professional tax payable', 'liability', self::PT_PAYABLE, 'Staff dues', false],
        ['2400', 'Loans', 'liability', null, 'Loans', false],

        ['3100', 'Capital', 'equity', self::CAPITAL, 'Capital', false],
        ['3200', 'Drawings', 'equity', self::DRAWINGS, 'Capital', false],
        ['3300', 'Opening balances', 'equity', self::OPENING, 'Capital', false],

        ['4100', 'Sales – services & labour', 'income', self::SALES_SERVICES, 'Sales', false],
        ['4110', 'Sales – parts & oil', 'income', self::SALES_PARTS, 'Sales', false],
        ['4120', 'Sales – other', 'income', self::SALES_OTHER, 'Sales', false],
        ['4900', 'Other income', 'income', null, 'Other income', false],
        ['4910', 'Staff penalties & deductions', 'income', self::STAFF_RECOVERIES, 'Other income', false],
        ['4950', 'Round off', 'income', self::ROUND_OFF, 'Other income', false],

        ['5100', 'Purchases – job parts', 'expense', self::PURCHASE_PARTS, 'Direct costs', true],
        ['5110', 'Third-party services', 'expense', self::THIRD_PARTY, 'Direct costs', true],
        ['5120', 'Oil & consumables used', 'expense', self::CONSUMABLES_USED, 'Direct costs', false],
        ['5130', 'Stock adjustments', 'expense', self::STOCK_ADJUSTMENT, 'Direct costs', false],
        ['6100', 'Salaries', 'expense', self::SALARIES, 'Staff costs', false],
        ['6110', 'Overtime', 'expense', self::OVERTIME, 'Staff costs', false],
        ['6120', 'Incentives & bonus', 'expense', self::INCENTIVES, 'Staff costs', false],
        ['6130', 'Employer PF & ESI', 'expense', self::EMPLOYER_CONTRIBUTIONS, 'Staff costs', false],
        ['6140', 'Staff reimbursements', 'expense', self::STAFF_REIMBURSEMENTS, 'Staff costs', true],
        ['6150', 'Staff welfare (tea, water, snacks)', 'expense', null, 'Staff costs', true],
        ['6200', 'Rent', 'expense', null, 'Overheads', true],
        ['6210', 'Electricity', 'expense', null, 'Overheads', true],
        ['6220', 'Fuel', 'expense', null, 'Overheads', true],
        ['6230', 'Fuel for customer cars', 'expense', null, 'Direct costs', true],
        ['6240', 'Stationery & printing', 'expense', null, 'Overheads', true],
        ['6250', 'Workshop maintenance', 'expense', null, 'Overheads', true],
        ['6260', 'Porter & courier', 'expense', null, 'Overheads', true],
        ['6270', 'Marketing & advertising', 'expense', null, 'Overheads', true],
        ['6280', 'Telephone & internet', 'expense', null, 'Overheads', true],
        ['6290', 'Bank charges', 'expense', null, 'Overheads', true],
        ['6300', 'Repairs to tools & equipment', 'expense', null, 'Overheads', true],
        ['6900', 'Miscellaneous expenses', 'expense', null, 'Overheads', true],
    ];

    /** @var array<string, int> */
    protected static array $ids = [];

    public static function seed(): void
    {
        $now = now();

        foreach (self::HEADS as [$code, $name, $type, $subType, $group, $expenseHead]) {
            $exists = $subType
                ? DB::table('accounts')->where('sub_type', $subType)->exists()
                : DB::table('accounts')->where('code', $code)->exists();
            if ($exists) {
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'sub_type' => $subType,
                'group' => $group,
                'is_system' => $subType !== null,
                'is_expense_head' => $expenseHead,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!DB::table('bank_accounts')->exists()) {
            DB::table('bank_accounts')->insert([
                'account_id' => DB::table('accounts')->where('sub_type', self::BANK)->value('id'),
                'name' => 'Current account',
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::flush();
    }

    /**
     * The id of a system head.
     */
    public static function id(string $subType): int
    {
        if (!isset(self::$ids[$subType])) {
            $id = DB::table('accounts')->where('sub_type', $subType)->orderBy('id')->value('id');
            if (!$id) {
                throw new \RuntimeException("Account head '{$subType}' is missing from the chart of accounts.");
            }
            self::$ids[$subType] = (int) $id;
        }

        return self::$ids[$subType];
    }

    public static function flush(): void
    {
        self::$ids = [];
    }
}
