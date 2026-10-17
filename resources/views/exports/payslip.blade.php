<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $slip['employee'] }} {{ $slip['period_label'] }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #C4161C; padding-bottom: 6px; margin-bottom: 10px; }
        .company { font-size: 20px; font-weight: bold; color: #C4161C; }
        .muted { color: #555; }
        .doc-title { font-size: 15px; font-weight: bold; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #999; }
        .net { font-size: 15px; font-weight: bold; margin-top: 10px; }
        .footer { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div class="muted">{{ $company['address'] }}</div>
    </div>

    <div class="doc-title">PAYSLIP — {{ strtoupper($slip['period_label']) }}</div>

    <table>
        <tr>
            <td style="width:50%">Employee: <b>{{ $slip['employee'] }}</b>@if($slip['employee_code']) ({{ $slip['employee_code'] }})@endif<br>
                @if($slip['designation']){{ $slip['designation'] }}@endif</td>
            <td>Days in month: {{ $slip['days_in_month'] }} &nbsp; Paid days: <b>{{ rtrim(rtrim(number_format($slip['paid_days'], 1), '0'), '.') }}</b><br>
                Present: {{ rtrim(rtrim(number_format($slip['present_days'], 1), '0'), '.') }} &nbsp; Leave: {{ rtrim(rtrim(number_format($slip['leave_days'], 1), '0'), '.') }} &nbsp; Loss of pay: {{ rtrim(rtrim(number_format($slip['lop_days'], 1), '0'), '.') }}<br>
                OT: {{ $slip['ot_hours'] }} h &nbsp; Off days worked: {{ $slip['off_day_works'] }}</td>
        </tr>
    </table>

    @php($earnings = collect($slip['lines'])->where('kind', 'earning'))
    @php($deductions = collect($slip['lines'])->where('kind', 'deduction'))
    @php($employer = collect($slip['lines'])->where('kind', 'employer'))

    <table style="margin-top:10px">
        <tr><th style="width:50%">Earnings</th><th class="num">₹</th><th style="width:30%">Deductions</th><th class="num">₹</th></tr>
        @php($rows = max($earnings->count(), $deductions->count()))
        @for($i = 0; $i < $rows; $i++)
            @php($e = $earnings->values()[$i] ?? null)
            @php($d = $deductions->values()[$i] ?? null)
            <tr>
                <td>{{ $e['label'] ?? '' }}</td><td class="num">{{ $e ? number_format($e['amount'], 2) : '' }}</td>
                <td>{{ $d['label'] ?? '' }}</td><td class="num">{{ $d ? number_format($d['amount'], 2) : '' }}</td>
            </tr>
        @endfor
        <tr class="total">
            <td>Gross earnings</td><td class="num">{{ number_format($slip['gross'], 2) }}</td>
            <td>Total deductions</td><td class="num">{{ number_format($slip['deductions'], 2) }}</td>
        </tr>
    </table>

    <div class="net">Net pay: ₹ {{ number_format($slip['net'], 2) }}</div>
    <div><i>{{ $words }}</i></div>

    @if($employer->isNotEmpty())
        <div class="muted" style="margin-top:8px">Paid by the employer on your behalf:
            @foreach($employer as $l){{ $l['label'] }} ₹ {{ number_format($l['amount'], 2) }}@if(!$loop->last), @endif @endforeach
        </div>
    @endif

    @if($slip['pay_status'] === 'paid')
        <div class="muted" style="margin-top:8px">Paid on {{ \Carbon\Carbon::parse($slip['paid_on'])->format('d-M-Y') }} by {{ ['bank_transfer' => 'bank transfer', 'upi' => 'UPI'][$slip['pay_method']] ?? $slip['pay_method'] }}@if($slip['pay_reference']) (Ref {{ $slip['pay_reference'] }})@endif</div>
    @endif

    <div class="footer">This is a computer-generated payslip and does not require a signature.</div>
</body>
</html>
