<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }} {{ $report['job_number'] }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #222; }
        .head { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 6px; margin-bottom: 8px; }
        .company { font-size: 20px; font-weight: bold; color: #1a365d; letter-spacing: 1px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; }
        .section { background: #1a365d; color: #fff; font-weight: bold; padding: 4px 6px; margin-top: 8px; }
        .acts td { padding: 3px 5px; border-bottom: 1px solid #eee; width: 33%; vertical-align: top; }
        .mark { font-weight: bold; color: #1a7f37; }
        .mark.na { color: #777; }
        .mark.no { color: #b42318; }
        .list td { border-bottom: 1px solid #ddd; padding: 4px 6px; }
        .sev-major { color: #b42318; font-weight: bold; }
        .sev-medium { color: #a15c00; font-weight: bold; }
        .note { margin-top: 12px; font-size: 9px; color: #555; }
        .sign td { padding-top: 26px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="company">{{ strtoupper($company['name']) }}</div>
        <div><b>{{ $report['title'] }}</b>@if($report['activities_carried_out']) &nbsp;•&nbsp; {{ $report['activities_carried_out'] }} Activity Points Carried Out @endif</div>
        <div class="muted">{{ $company['address'] }}@if($company['phone']) &nbsp;|&nbsp; Mob: {{ $company['phone'] }}@endif</div>
    </div>

    <table class="info">
        <tr>
            <td><b>Customer Name:</b> {{ $report['customer'] }}</td>
            <td style="text-align:right"><b>Date:</b> {{ \Illuminate\Support\Carbon::parse($report['date'])->format('d-M-Y') }}</td>
        </tr>
        <tr>
            <td><b>Car Model:</b> {{ $report['car'] ?: '-' }}</td>
            <td style="text-align:right"><b>KMs:</b> {{ $report['km_reading'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><b>Regn No:</b> {{ $report['registration_no'] }}</td>
            <td style="text-align:right"><b>Job:</b> {{ $report['job_number'] }}</td>
        </tr>
    </table>

    @foreach($report['sections'] as $section)
        <div class="section">{{ $section['name'] }} &nbsp;•&nbsp; {{ count($section['items']) }} activities</div>
        <table class="acts">
            @foreach(array_chunk($section['items'], 3) as $row)
                <tr>
                    @foreach($row as $i => $item)
                        <td>
                            {{ $loop->parent->index * 3 + $i + 1 }}. {{ $item['name'] }}
                            <span class="mark {{ $item['mark'] === 'N/A' ? 'na' : ($item['mark'] === '✗' ? 'no' : '') }}">{{ $item['mark'] }}</span>
                        </td>
                    @endforeach
                    @for($pad = count($row); $pad < 3; $pad++)<td></td>@endfor
                </tr>
            @endforeach
        </table>
    @endforeach

    @if(count($report['additional_jobs']))
        <div class="section">ADDITIONAL JOBS DONE &nbsp;(charges: refer to Estimate)</div>
        <table class="list">
            @foreach($report['additional_jobs'] as $i => $job)
                <tr><td style="width:24px">{{ $i + 1 }}</td><td>{{ $job }}</td></tr>
            @endforeach
        </table>
    @endif

    @if(count($report['observations']))
        <div class="section">OBSERVATIONS &nbsp;(for your attention — advisable at / before next service)</div>
        <table class="list">
            @foreach($report['observations'] as $i => $o)
                <tr>
                    <td style="width:24px">{{ $i + 1 }}</td>
                    <td>{{ $o['text'] }}</td>
                    <td style="width:70px" class="sev-{{ $o['severity'] }}">{{ $o['severity'] ? ucfirst($o['severity']) : '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(count($report['not_approved']))
        <div class="section">{{ strtoupper($report['not_approved_label']) }}</div>
        <table class="list">
            @foreach($report['not_approved'] as $i => $item)
                <tr><td style="width:24px">{{ $i + 1 }}</td><td>{{ $item }}</td></tr>
            @endforeach
        </table>
    @endif

    @if($report['next_service_km'] || $report['next_service_date'])
        <p><b>Next Service Due:</b>
            @if($report['next_service_km']) {{ number_format($report['next_service_km']) }} KMs @endif
            @if($report['next_service_km'] && $report['next_service_date']) and/or @endif
            @if($report['next_service_date']) {{ \Illuminate\Support\Carbon::parse($report['next_service_date'])->format('d.m.Y') }} @endif
            (whichever is earlier)
        </p>
    @endif

    @if($report['advisor_notes'])
        <p>{{ $report['advisor_notes'] }}</p>
    @endif

    <div class="note">
        Note: Despite thorough checking, technical failure of parts can occur any time — especially wear &amp; tear components, electronic parts, sensors and battery.
    </div>

    <table class="sign">
        <tr>
            <td>Service Advisor: {{ $report['advisor'] ?? '________________________' }}</td>
            <td style="text-align:right">Customer Signature: ________________________</td>
        </tr>
    </table>
</body>
</html>
