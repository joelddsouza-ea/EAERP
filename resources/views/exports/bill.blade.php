<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $bill->isProforma() ? 'Proforma' : 'Tax Invoice' }} {{ $bill->invoice_number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 6px; margin-bottom: 8px; }
        .company { font-size: 20px; font-weight: bold; color: #1a365d; letter-spacing: 1px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        .title-row td { border: none; padding: 2px 0; }
        .doc-title { font-size: 15px; font-weight: bold; color: #1a365d; }
        .info td { padding: 2px 4px; border: none; vertical-align: top; }
        .lines { margin-top: 8px; }
        .lines th { background: #1a365d; color: #fff; padding: 4px; text-align: left; font-size: 9px; }
        .lines td { border-bottom: 1px solid #ddd; padding: 3px 4px; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 2px 4px; }
        .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #1a365d; padding-top: 5px; }
        .words { font-style: italic; margin-top: 4px; }
        .box { border: 1px solid #ccc; padding: 6px; margin-top: 10px; }
        .footer { margin-top: 14px; font-size: 9px; color: #555; }
        .stamp { color: #a15c00; font-weight: bold; }
        .section td { background: #eef2f7; font-weight: bold; color: #1a365d; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div class="muted">{{ $company['tagline'] }}</div>
        <div class="muted">{{ $company['address'] }}@if($company['phone']) &nbsp;|&nbsp; Mob: {{ $company['phone'] }}@endif</div>
        @if($company['gstin'])<div class="muted">GSTIN: {{ $company['gstin'] }}</div>@endif
    </div>

    <table class="title-row">
        <tr>
            <td class="doc-title">
                {{ $bill->isProforma() ? 'PROFORMA INVOICE' : 'TAX INVOICE' }}
                @if($bill->isProforma() && $bill->status === 'draft')<span class="stamp">(DRAFT)</span>@endif
            </td>
            <td class="num">
                No: <b>{{ $bill->invoice_number }}</b><br>
                Date: {{ $bill->invoice_date?->format('d-M-Y') }}<br>
                Job: {{ $jobCard->job_number }}
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td style="width:55%">
                <b>Bill to:</b> {{ $bill->customer?->name }}<br>
                @if($bill->customer?->phone)Mob: {{ $bill->customer->phone }}<br>@endif
                @if($bill->customer?->address){{ $bill->customer->address }}<br>@endif
                @if($bill->customer_gstin)<b>GSTIN:</b> {{ $bill->customer_gstin }}@endif
            </td>
            <td>
                <b>Car:</b> {{ trim(($jobCard->vehicle?->make?->name ?? '') . ' ' . ($jobCard->vehicle?->model?->name ?? '')) ?: '-' }}<br>
                <b>Regn No:</b> {{ $jobCard->vehicle?->registration_no }}<br>
                <b>KMs:</b> {{ $jobCard->km_reading ?? '-' }}<br>
                <b>Place of supply:</b> {{ $bill->place_of_supply ?? '-' }}
            </td>
        </tr>
    </table>

    <table class="lines">
        <tr>
            <th style="width:20px">#</th>
            <th>Description</th>
            <th>HSN/SAC</th>
            <th class="num">Qty</th>
            <th class="num">Rate</th>
            <th class="num">Disc.</th>
            <th class="num">Taxable</th>
            <th class="num">GST</th>
            <th class="num">Amount</th>
        </tr>
        @php($headings = ['plan' => $planName ? $planName . ' — service plan' : 'Service plan', 'additional' => 'Additional parts & labour', 'trips' => 'Pickup, drop & home visit', 'other' => 'Other items'])
        @php($current = null)
        @foreach($bill->items as $i => $item)
            @php($section = $item->section ?? ($item->estimate_line_key ? 'additional' : 'other'))
            @if($section !== $current)
                @php($current = $section)
                <tr class="section"><td colspan="9">{{ $headings[$section] ?? '' }}</td></tr>
            @endif
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->hsn_code }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="num">{{ (float) $item->discount > 0 ? number_format((float) $item->discount, 2) : '' }}</td>
                <td class="num">{{ number_format((float) $item->taxable_amount, 2) }}</td>
                <td class="num">{{ (float) $item->tax_rate > 0 ? rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.') . '%' : 'Nil' }}</td>
                <td class="num">{{ number_format((float) $item->total, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="totals" style="margin-top:6px; width:50%; margin-left:50%">
        @php([$beforeDiscount, $lineDiscount] = $bill->discountBreakup())
        @if($lineDiscount >= 0.01)
            <tr><td>Value before discount</td><td class="num">{{ number_format($beforeDiscount, 2) }}</td></tr>
            <tr><td>Less: Discount</td><td class="num">-{{ number_format($lineDiscount, 2) }}</td></tr>
        @endif
        <tr><td>Taxable value</td><td class="num">{{ number_format((float) $bill->taxable_amount, 2) }}</td></tr>
        @if($bill->is_igst)
            <tr><td>IGST</td><td class="num">{{ number_format((float) $bill->igst, 2) }}</td></tr>
        @else
            <tr><td>CGST</td><td class="num">{{ number_format((float) $bill->cgst, 2) }}</td></tr>
            <tr><td>SGST</td><td class="num">{{ number_format((float) $bill->sgst, 2) }}</td></tr>
        @endif
        @if(abs($bill->roundOff()) >= 0.01)
            <tr><td>Round off</td><td class="num">{{ number_format($bill->roundOff(), 2) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL (₹)</td><td class="num">{{ number_format((float) $bill->total_amount, 2) }}</td></tr>
        @if($paid > 0)
            <tr><td>Paid</td><td class="num">{{ number_format($paid, 2) }}</td></tr>
            @if((float) $bill->balance_amount < 0)
                <tr><td><b>Refund due</b></td><td class="num"><b>{{ number_format(-(float) $bill->balance_amount, 2) }}</b></td></tr>
            @else
                <tr><td><b>Balance due</b></td><td class="num"><b>{{ number_format((float) $bill->balance_amount, 2) }}</b></td></tr>
            @endif
        @endif
    </table>
    <div class="words">{{ $words }}</div>

    {{-- GST by HSN/SAC --}}
    <table class="lines" style="margin-top:8px">
        <tr>
            <th>HSN/SAC</th>
            <th class="num">Rate</th>
            <th class="num">Taxable value</th>
            @if($bill->is_igst)<th class="num">IGST</th>@else<th class="num">CGST</th><th class="num">SGST</th>@endif
            <th class="num">Total tax</th>
        </tr>
        @foreach($bill->items->groupBy(fn ($i) => ($i->hsn_code ?? '-') . '|' . (float) $i->tax_rate) as $key => $group)
            <tr>
                <td>{{ explode('|', $key)[0] }}</td>
                <td class="num">{{ (float) $group->first()->tax_rate > 0 ? rtrim(rtrim(number_format((float) $group->first()->tax_rate, 2), '0'), '.') . '%' : 'Nil' }}</td>
                <td class="num">{{ number_format($group->sum('taxable_amount'), 2) }}</td>
                @if($bill->is_igst)
                    <td class="num">{{ number_format($group->sum('igst'), 2) }}</td>
                @else
                    <td class="num">{{ number_format($group->sum('cgst'), 2) }}</td>
                    <td class="num">{{ number_format($group->sum('sgst'), 2) }}</td>
                @endif
                <td class="num">{{ number_format($group->sum('cgst') + $group->sum('sgst') + $group->sum('igst'), 2) }}</td>
            </tr>
        @endforeach
    </table>

    @if($bill->terms)
        <div class="box"><b>Terms:</b><br>{!! nl2br(e($bill->terms)) !!}</div>
    @endif

    <div class="footer">
        @if($bill->isProforma())
            This is a proforma invoice, not a tax invoice.
        @endif
        This is a computer-generated document and does not require a signature.
    </div>
</body>
</html>
