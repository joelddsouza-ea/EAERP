<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1a365d;
        }
        .invoice-title {
            font-size: 18px;
            font-weight: bold;
            text-align: right;
            color: #1a365d;
        }
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .info-box {
            width: 48%;
        }
        .info-box h3 {
            background: #f0f0f0;
            padding: 5px 10px;
            margin: 0 0 10px 0;
            font-size: 12px;
        }
        .info-box p {
            margin: 3px 0;
            padding-left: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background: #1a365d;
            color: white;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .totals-table {
            width: 300px;
            margin-left: auto;
        }
        .totals-table td {
            border: none;
            padding: 5px 10px;
        }
        .totals-table .total-row {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #333;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 10px;
        }
        .terms {
            margin-top: 20px;
            padding: 10px;
            background: #f9f9f9;
        }
        .terms h4 {
            margin: 0 0 10px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <table style="border: none;">
            <tr>
                <td style="border: none; width: 60%;">
                    <div class="company-name">{{ $invoice->branch->name ?? 'Express Autocare' }}</div>
                    <p>{{ $invoice->branch->address ?? '' }}</p>
                    <p>GSTIN: {{ $invoice->branch->gstin ?? '' }}</p>
                </td>
                <td style="border: none; text-align: right;">
                    <div class="invoice-title">TAX INVOICE</div>
                    <p><strong>Invoice No:</strong> {{ $invoice->invoice_number }}</p>
                    <p><strong>Date:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</p>
                    @if($invoice->due_date)
                    <p><strong>Due Date:</strong> {{ $invoice->due_date->format('d/m/Y') }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="info-section">
        <div class="info-box" style="float: left;">
            <h3>Bill To</h3>
            <p><strong>{{ $invoice->customer->name }}</strong></p>
            @if($invoice->customer->company_name)
            <p>{{ $invoice->customer->company_name }}</p>
            @endif
            @if($invoice->customer->address)
            <p>{{ $invoice->customer->address }}</p>
            @endif
            @if($invoice->customer->phone)
            <p>Phone: {{ $invoice->customer->phone }}</p>
            @endif
            @if($invoice->customer->gstin)
            <p>GSTIN: {{ $invoice->customer->gstin }}</p>
            @endif
        </div>
        <div class="info-box" style="float: right;">
            @if($invoice->jobCard)
            <h3>Vehicle Details</h3>
            <p><strong>Job Card:</strong> {{ $invoice->jobCard->job_number }}</p>
            @if($invoice->jobCard->vehicle)
            <p><strong>Vehicle:</strong> {{ $invoice->jobCard->vehicle->full_name }}</p>
            <p><strong>Reg. No:</strong> {{ $invoice->jobCard->vehicle->registration_no }}</p>
            @endif
            @if($invoice->jobCard->km_reading)
            <p><strong>KM Reading:</strong> {{ number_format($invoice->jobCard->km_reading) }}</p>
            @endif
            @endif
        </div>
        <div style="clear: both;"></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Description</th>
                <th style="width: 10%;">HSN</th>
                <th class="text-right" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 12%;">Rate</th>
                <th class="text-right" style="width: 8%;">Tax %</th>
                <th class="text-right" style="width: 10%;">Tax</th>
                <th class="text-right" style="width: 10%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->hsn_code }}</td>
                <td class="text-right">{{ $item->quantity }} {{ $item->unit }}</td>
                <td class="text-right">₹{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ $item->tax_rate }}%</td>
                <td class="text-right">₹{{ number_format($item->cgst + $item->sgst + $item->igst, 2) }}</td>
                <td class="text-right">₹{{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal:</td>
            <td class="text-right">₹{{ number_format($invoice->taxable_amount, 2) }}</td>
        </tr>
        @if($invoice->cgst > 0)
        <tr>
            <td>CGST:</td>
            <td class="text-right">₹{{ number_format($invoice->cgst, 2) }}</td>
        </tr>
        <tr>
            <td>SGST:</td>
            <td class="text-right">₹{{ number_format($invoice->sgst, 2) }}</td>
        </tr>
        @endif
        @if($invoice->igst > 0)
        <tr>
            <td>IGST:</td>
            <td class="text-right">₹{{ number_format($invoice->igst, 2) }}</td>
        </tr>
        @endif
        @if($invoice->discount > 0)
        <tr>
            <td>Discount:</td>
            <td class="text-right">-₹{{ number_format($invoice->discount, 2) }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>Total:</td>
            <td class="text-right">₹{{ number_format($invoice->total_amount, 2) }}</td>
        </tr>
        @if($invoice->paid_amount > 0)
        <tr>
            <td>Paid:</td>
            <td class="text-right">₹{{ number_format($invoice->paid_amount, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>Balance:</td>
            <td class="text-right">₹{{ number_format($invoice->balance_amount, 2) }}</td>
        </tr>
        @endif
    </table>

    @if($invoice->terms)
    <div class="terms">
        <h4>Terms & Conditions</h4>
        {!! nl2br(e($invoice->terms)) !!}
    </div>
    @endif

    <div class="footer">
        <p>This is a computer-generated invoice and does not require a signature.</p>
        <p>Thank you for your business!</p>
    </div>
</body>
</html>
