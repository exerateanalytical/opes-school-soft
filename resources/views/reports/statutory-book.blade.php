<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $bookLabel }}</title>
    <style>
        @page { margin: 96px 32px 56px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        header { position: fixed; top: -76px; left: 0; right: 0; text-align: center; font-size: 9px; }
        header .state { font-size: 7.5px; text-transform: uppercase; letter-spacing: .04em; }
        footer { position: fixed; bottom: -44px; left: 0; right: 0; font-size: 7.5px; text-align: center; color: #444; }
        .pagenum:before { content: counter(page); }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 2px 4px; }
        th { background: #eaeaea; text-align: left; }
        td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }
    </style>
</head>
<body>
<header>
    <div class="state">République du Cameroun — Paix · Travail · Patrie</div>
    <strong>{{ $schoolName }}</strong> — {{ $bookLabel }} — exercice {{ $fiscalYearCode }}<br>
    {{ $periodStart }} → {{ $periodEnd }}
</header>

<footer>
    Page <span class="pagenum"></span> — généré le {{ $generatedAt }} par {{ $generatedBy }}
    @if ($coteParaphe !== '') · Cote et paraphe : {{ $coteParaphe }} @endif
</footer>

<table>
    <thead>
    <tr>
        @foreach ($headers as $header)
            <th>{{ $header }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            @foreach ($row as $cell)
                <td class="{{ is_int($cell) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', ' ') : $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
