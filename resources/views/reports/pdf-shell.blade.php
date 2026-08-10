<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; }
        .title { font-size: 16px; font-weight: bold; color: #0F5132; margin-bottom: 2px; }
        .meta { font-size: 8px; color: #666; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #0F5132; color: #fff; text-align: left;
            padding: 5px 6px; font-size: 9px; text-transform: uppercase;
        }
        tbody td { padding: 4px 6px; border-bottom: 1px solid #e5e0d8; font-size: 9px; }
        tbody tr:nth-child(even) { background: #f7f5f0; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="title">{{ $title }}</div>
    <div class="meta">OPES SCHOOL &middot; Generated {{ $generatedAt }}</div>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">No data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">OPES SCHOOL - Confidential</div>
</body>
</html>
