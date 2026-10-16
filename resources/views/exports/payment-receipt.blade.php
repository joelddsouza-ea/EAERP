<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->payment_number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 6px; margin-bottom: 10px; }
        .company { font-size: 20px; font-weight: bold; color: #1a365d; }
        .muted { color: #555; }
        .doc-title { font-size: 15px; font-weight: bold; color: #1a365d; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px; border-bottom: 1px solid #eee; }
        .amount { font-size: 16px; font-weight: bold; }
        .footer { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div class="muted">{{ $company['address'] }}@if($company['phone']) &nbsp;|&nbsp; Mob: {{ $company['phone'] }}@endif</div>
    </div>

    @php($methodName = fn ($m) => ['customer_credit' => 'Customer credit', 'bank_transfer' => 'Bank transfer', 'upi' => 'UPI'][$m] ?? ucwords(str_replace('_', ' ', $m)))
    @php($out = (float) $payment->amount < 0)
    <div class="doc-title">{{ $payment->stage === 'refund' ? 'REFUND VOUCHER' : ($payment->stage === 'credit' ? 'CREDIT NOTE — KEPT FOR THE CUSTOMER' : 'PAYMENT RECEIPT') }}</div>

    <table>
        <tr><td style="width:35%">Receipt No</td><td><b>{{ $payment->payment_number }}</b></td></tr>
        <tr><td>Date</td><td>{{ $payment->payment_date?->format('d-M-Y') }}</td></tr>
        <tr><td>{{ $out ? 'Customer' : 'Received from' }}</td><td>{{ $jobCard->customer?->name }}</td></tr>
        <tr><td>Car / Job</td><td>{{ $jobCard->vehicle?->registration_no }} / {{ $jobCard->job_number }}</td></tr>
        <tr><td>Amount</td><td class="amount">₹ {{ number_format(abs((float) $payment->amount), 2) }}</td></tr>
        <tr><td></td><td><i>{{ $words }}</i></td></tr>
        @if($payment->parts->count() > 1)
            <tr><td>Paid by</td><td>
                @foreach($payment->parts as $part)
                    {{ $methodName($part->method) }}: ₹ {{ number_format(abs((float) $part->amount), 2) }}@if($part->reference_number) (Ref {{ $part->reference_number }})@endif<br>
                @endforeach
            </td></tr>
        @else
            <tr><td>Mode</td><td>{{ $methodName($payment->method) }}@if($payment->reference_number) — Ref {{ $payment->reference_number }}@endif</td></tr>
        @endif
        <tr><td>Towards</td><td>{{ ['advance' => 'Advance', 'part' => 'Part payment', 'final' => 'Final payment', 'refund' => 'Refund of the amount paid beyond the bill', 'credit' => 'Amount paid beyond the bill, kept as credit for a later job'][$payment->stage] ?? 'Payment' }}</td></tr>
        @if($payment->notes)<tr><td>Note</td><td>{{ $payment->notes }}</td></tr>@endif
        <tr><td>{{ $out ? 'Recorded by' : 'Received by' }}</td><td>{{ $payment->receivedBy?->name }}</td></tr>
        @if($summary['bill_total'] !== null)
            <tr><td>Bill total / paid to date</td><td>₹ {{ number_format($summary['bill_total'], 2) }} / ₹ {{ number_format($summary['paid'], 2) }}</td></tr>
            <tr><td>Balance</td><td><b>₹ {{ number_format(max(0, $summary['balance']), 2) }}</b></td></tr>
        @endif
    </table>

    <div class="footer">This is a computer-generated receipt and does not require a signature.</div>
</body>
</html>
