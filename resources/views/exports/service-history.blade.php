<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Service history {{ $book['vehicle']['registration_no'] }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #c4161c; padding-bottom: 6px; margin-bottom: 8px; }
        .company { font-size: 20px; font-weight: bold; color: #c4161c; letter-spacing: 1px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; }
        .section { background: #c4161c; color: #fff; font-weight: bold; padding: 4px 6px; margin-top: 10px; }
        .list th { text-align: left; border-bottom: 1px solid #999; padding: 4px 6px; }
        .list td { border-bottom: 1px solid #ddd; padding: 4px 6px; }
        .right { text-align: right; }
        .note { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($workshop) }}</div>
        <div><b>Service history</b></div>
    </div>

    <table class="info">
        <tr>
            <td><b>Car:</b> {{ $book['vehicle']['car'] }} @if($book['vehicle']['year'])({{ $book['vehicle']['year'] }})@endif</td>
            <td class="right"><b>Registration:</b> {{ $book['vehicle']['registration_no'] }}</td>
        </tr>
        <tr>
            <td><b>Owner:</b> {{ $customer->name }}</td>
            <td class="right"><b>Fuel:</b> {{ $book['vehicle']['fuel_type'] ? ucfirst($book['vehicle']['fuel_type']) : '-' }}</td>
        </tr>
        <tr>
            <td><b>Last service:</b> {{ $book['vehicle']['last_service_at'] ? \Illuminate\Support\Carbon::parse($book['vehicle']['last_service_at'])->format('d-M-Y') : '-' }}@if($book['vehicle']['last_service_km']) at {{ number_format($book['vehicle']['last_service_km']) }} km @endif</td>
            <td class="right"><b>Next service due:</b> {{ $book['vehicle']['next_service_due'] ? \Illuminate\Support\Carbon::parse($book['vehicle']['next_service_due'])->format('d-M-Y') : '-' }}</td>
        </tr>
    </table>

    <div class="section">Visits ({{ count($book['visits']) }})</div>
    <table class="list">
        <tr><th>Date</th><th>Job</th><th>Work</th><th class="right">KMs</th></tr>
        @forelse($book['visits'] as $v)
            <tr>
                <td>{{ $v['date'] ? \Illuminate\Support\Carbon::parse($v['date'])->format('d-M-Y') : '-' }}</td>
                <td>{{ $v['job_number'] }}</td>
                <td>{{ $v['work'] }}</td>
                <td class="right">{{ $v['km'] ? number_format($v['km']) : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No completed visits yet.</td></tr>
        @endforelse
    </table>

    @if(count($book['observations']))
        <div class="section">Recommended work not yet done</div>
        <table class="list">
            @foreach($book['observations'] as $o)
                <tr><td>{{ $o['text'] }}@if($o['remark']) <span class="muted">({{ $o['remark'] }})</span>@endif</td><td class="right">{{ $o['date'] ? \Illuminate\Support\Carbon::parse($o['date'])->format('d-M-Y') : '' }}</td></tr>
            @endforeach
        </table>
    @endif

    <div class="note">Generated on {{ $generated->format('d-M-Y H:i') }} from the {{ $workshop }} customer app. Visit details and invoices are kept at the workshop.</div>
</body>
</html>
