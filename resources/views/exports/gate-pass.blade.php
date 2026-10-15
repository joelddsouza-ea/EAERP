<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Gate Pass {{ $pass->number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 6px; margin-bottom: 10px; }
        .company { font-size: 20px; font-weight: bold; color: #1a365d; }
        .muted { color: #555; }
        .doc-title { font-size: 18px; font-weight: bold; color: #1a365d; text-align: center; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px; border-bottom: 1px solid #eee; }
        .warn { border: 1px solid #a15c00; color: #a15c00; padding: 6px; margin-top: 10px; }
        .sign td { border: none; padding-top: 40px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div class="muted">{{ $company['address'] }}@if($company['phone']) &nbsp;|&nbsp; Mob: {{ $company['phone'] }}@endif</div>
    </div>

    <div class="doc-title">GATE PASS — {{ $pass->number }}</div>

    <table>
        <tr><td style="width:35%">Job</td><td><b>{{ $jobCard->job_number }}</b></td></tr>
        <tr><td>Regn No</td><td><b>{{ $jobCard->vehicle?->registration_no }}</b></td></tr>
        <tr><td>Car</td><td>{{ trim(($jobCard->vehicle?->make?->name ?? '') . ' ' . ($jobCard->vehicle?->model?->name ?? '')) ?: '-' }}</td></tr>
        <tr><td>Customer</td><td>{{ $jobCard->customer?->name }} {{ $jobCard->customer?->phone }}</td></tr>
        <tr><td>Issued</td><td>{{ $pass->issued_at?->format('d-M-Y H:i') }} by {{ $pass->issuedBy?->name ?? 'system (paid in full)' }}</td></tr>
        @if($pass->vehicle_out_at)
            <tr><td>Vehicle out</td><td>{{ $pass->vehicle_out_at->format('d-M-Y H:i') }} ({{ $pass->vehicleOutBy?->name }})</td></tr>
        @endif
    </table>

    @if($pass->override)
        <div class="warn">
            Issued before full payment. Amount due: ₹ {{ number_format((float) $pass->amount_due, 2) }}<br>
            Reason: {{ $pass->override_reason }}
        </div>
    @endif

    <table class="sign">
        <tr><td>Advisor</td><td>Security</td><td>Customer</td></tr>
    </table>
</body>
</html>
