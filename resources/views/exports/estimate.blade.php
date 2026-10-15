<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Estimate {{ $jobCard->job_number }}</title>
    <style>
        /* DejaVu Sans carries the rupee sign and tick marks in dompdf */
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 6px; margin-bottom: 8px; }
        .company { font-size: 20px; font-weight: bold; color: #1a365d; letter-spacing: 1px; }
        .muted { color: #555; }
        .title-row td { border: none; padding: 2px 0; }
        .doc-title { font-size: 15px; font-weight: bold; color: #1a365d; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; border: none; }
        .lines { margin-top: 8px; }
        .lines th { background: #1a365d; color: #fff; padding: 5px; text-align: left; font-size: 10px; }
        .lines td { border-bottom: 1px solid #ddd; padding: 4px 5px; }
        .num { text-align: right; white-space: nowrap; }
        .section { background: #eef2f7; font-weight: bold; color: #1a365d; }
        .subtotal td { font-weight: bold; border-top: 1px solid #999; }
        .declined td { color: #888; }
        .tag { font-size: 9px; color: #a15c00; }
        .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #1a365d; padding-top: 6px; }
        .words { font-style: italic; margin-top: 4px; }
        .box { border: 1px solid #ccc; padding: 6px; margin-top: 10px; }
        .footer { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div class="muted">{{ $company['tagline'] }}</div>
        <div class="muted">{{ $company['address'] }}@if($company['phone']) &nbsp;|&nbsp; Mob: {{ $company['phone'] }}@endif</div>
    </div>

    <table class="title-row">
        <tr>
            <td class="doc-title">ESTIMATE @if($estimate['version'] > 1)<span class="muted" style="font-size:10px">(revision {{ $estimate['version'] }})</span>@endif</td>
            <td class="num">Date: {{ \Illuminate\Support\Carbon::parse($estimate['sent_at'] ?? now())->format('d-M-Y') }}<br>Job: {{ $jobCard->job_number }}</td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td><b>Customer Name:</b> {{ $jobCard->customer?->name }}</td>
            <td><b>Fuel Type:</b> {{ ucfirst($jobCard->vehicle?->fuel_type ?? '-') }}</td>
        </tr>
        <tr>
            <td><b>Car Model:</b> {{ trim(($jobCard->vehicle?->make?->name ?? '') . ' ' . ($jobCard->vehicle?->model?->name ?? '')) ?: '-' }}</td>
            <td><b>KMs:</b> {{ $jobCard->km_reading ?? '-' }}</td>
        </tr>
        <tr>
            <td><b>Regn No:</b> {{ $jobCard->vehicle?->registration_no }}</td>
            <td><b>Payment Mode:</b> {{ $estimate['payment_mode'] ?? '-' }}</td>
        </tr>
        @if($estimate['package_name'])
        <tr>
            <td><b>Service Plan:</b> {{ $estimate['package_name'] }}</td>
            <td><b>Car Type:</b> {{ $estimate['category_name'] }} &nbsp; <b>Oil Grade:</b> {{ $estimate['oil_type_name'] }}</td>
        </tr>
        @endif
    </table>

    <table class="lines">
        <tr>
            <th style="width:58%">Item Description</th>
            <th class="num">Qty</th>
            <th class="num">Rate</th>
            <th class="num">Amount</th>
        </tr>

        @if(count($estimate['plan_lines']))
            <tr class="section"><td colspan="4">{{ $estimate['package_name'] }}</td></tr>
            @foreach($estimate['plan_lines'] as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['kind'] === 'labour_balance' ? '' : rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</td>
                    <td class="num">{{ $line['kind'] === 'labour_balance' ? '' : number_format($line['rate'], 2) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal"><td colspan="3">{{ $estimate['package_name'] }} — Package Total</td><td class="num">{{ number_format($estimate['package_price'], 2) }}</td></tr>
        @endif

        @php($extras = array_values(array_filter($estimate['additional_lines'], fn ($l) => $l['approval_status'] !== 'declined')))
        @if(count($extras))
            <tr class="section"><td colspan="4">ADDITIONAL PARTS &amp; LABOUR — extra work over &amp; above the package</td></tr>
            {{-- Work the customer did not approve is listed under Observations --}}
            @foreach($extras as $line)
                <tr>
                    <td>
                        {{ $line['description'] }}
                        @if($line['approval_status'] !== 'approved')<br><span class="tag">{{ $line['approval_label'] }}</span>@endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</td>
                    <td class="num">{{ number_format($line['rate'], 2) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @endforeach
        @endif
    </table>

    <table class="lines" style="margin-top:6px">
        @if($estimate['package_name'])
            <tr><td>Service Plan Total</td><td class="num">{{ number_format($estimate['package_price'], 2) }}</td></tr>
            @if($estimate['plan_discount'] > 0)
                <tr><td>Discount on Service Plan</td><td class="num">− {{ number_format($estimate['plan_discount'], 2) }}</td></tr>
            @endif
        @endif
        @if(count($extras))
            <tr><td>Additional Jobs Total</td><td class="num">{{ number_format($estimate['extras_subtotal'], 2) }}</td></tr>
            @if($estimate['extras_discount'] > 0)
                <tr><td>Discount on Additional Jobs</td><td class="num">− {{ number_format($estimate['extras_discount'], 2) }}</td></tr>
            @endif
        @endif
        <tr class="grand"><td>GRAND TOTAL (₹)</td><td class="num">{{ number_format($estimate['grand_total'], 0) }}</td></tr>
    </table>
    <div class="words">{{ $estimate['grand_total_words'] }}</div>
    <div class="muted" style="font-size:9px">All prices include GST.</div>

    @if($estimate['next_service_km'] || $estimate['next_service_date'])
        <div class="box">
            <b>Next Service Due:</b>
            @if($estimate['next_service_km']) {{ number_format($estimate['next_service_km']) }} KMs @endif
            @if($estimate['next_service_km'] && $estimate['next_service_date']) and/or @endif
            @if($estimate['next_service_date']) {{ \Illuminate\Support\Carbon::parse($estimate['next_service_date'])->format('d.m.Y') }} @endif
            <span class="muted">(whichever is earlier)</span>
        </div>
    @endif

    @if(count($estimate['observations']))
        <div class="box">
            <b>Observations &amp; Recommendations:</b>
            <table class="lines" style="margin-top:4px">
                <tr><th style="width:24px">#</th><th>Observation</th><th style="width:38%">Remarks</th></tr>
                @foreach($estimate['observations'] as $i => $o)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $o['text'] }}</td>
                        <td>{{ $o['remark'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if($estimate['notes'])
        <div class="box">{{ $estimate['notes'] }}</div>
    @endif

    <div class="footer">
        * No Guarantee or Warranty on Spare Parts. This is a computer-generated estimate and does not require a stamp or signature.
    </div>
</body>
</html>
