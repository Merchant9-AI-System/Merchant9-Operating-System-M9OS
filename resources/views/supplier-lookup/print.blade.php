<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier Lookup - {{ $supplierName }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 12px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1c1917; background: #fff; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .brand { display: block; height: 32px; width: auto; margin: 0 0 10px; }
        .meta { color: #57534e; margin: 0 0 4px; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 12px; border: 1px solid #a8a29e; border-radius: 6px; background: #fafaf9; color: inherit; text-decoration: none; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #d6d3d1; text-align: left; vertical-align: middle; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #57534e; border-bottom: 1px solid #1c1917; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { font-weight: 600; white-space: nowrap; }
        .muted { color: #57534e; font-size: 10px; }
        .thumb { width: 36px; height: 36px; object-fit: contain; border-radius: 4px; background: #fafaf9; display: block; }
        .thumb-empty { width: 36px; height: 36px; border-radius: 4px; background: #f5f5f4; }
        .badge { display: inline-block; margin-top: 2px; padding: 0 6px; border: 1px solid #a8a29e; border-radius: 999px; font-size: 9px; font-weight: 600; white-space: nowrap; }
        .note { font-size: 10px; color: #57534e; margin-top: 8px; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            @page { size: A4 portrait; margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
    </div>

    <img class="brand" src="{{ asset('images/brand/merchant9-lockup-horizontal.png') }}" alt="Merchant9 Operating Systems">
    <h1>Supplier Lookup - {{ $supplierName }} ({{ $supplierCode }})</h1>
    <p class="meta">
        {{ $days }} hari terakhir &middot;
        {{ $branchMode ? 'Cawangan: '.implode(', ', $branches) : 'Semua cawangan' }} &middot;
        Disusun: {{ $sortLabel }} &middot;
        {{ now()->format('d/m/Y H:i') }} &middot; {{ $generatedBy }}
    </p>
    <p class="meta">{{ number_format($total) }} {{ $branchMode ? 'baris' : 'design' }}</p>

    <table>
        <thead>
            <tr>
                <th>{{ $labels['image'] }}</th>
                <th>{{ $labels['code'] }}</th>
                @if ($branchMode)
                    <th>{{ $labels['store'] }}</th>
                @endif
                <th class="num">{{ $labels['requested'] }}</th>
                <th class="num">{{ $labels['buy'] }}</th>
                <th class="num">{{ $labels['sold'] }}</th>
                <th class="num">{{ $labels['available'] }}</th>
                <th class="num">{{ $labels['percent'] }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>
                        @if ($row['image_url'])
                            <img class="thumb" src="{{ $row['image_url'] }}" alt="{{ $row['code'] }}" loading="lazy">
                            @if ($row['image_reference'])
                                <span class="badge">Rujukan</span>
                            @endif
                        @else
                            <div class="thumb-empty"></div>
                        @endif
                    </td>
                    <td>
                        <div class="code">{{ $row['code'] }}</div>
                        @if ($row['nickname'])
                            <div class="muted">{{ $row['nickname'] }}</div>
                        @endif
                    </td>
                    @if ($branchMode)
                        <td>{{ $row['store'] }}</td>
                    @endif
                    <td class="num">{{ number_format($row['requested']) }}</td>
                    <td class="num">{{ number_format($row['buy']) }}</td>
                    <td class="num">{{ number_format($row['sold']) }}</td>
                    <td class="num">{{ number_format($row['available']) }}</td>
                    <td class="num">{{ number_format($row['percent'], 1) }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $branchMode ? 8 : 7 }}" class="muted">Tiada design bagi supplier, tempoh dan cawangan ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($total > $maxRows)
        <p class="note">Menunjukkan {{ number_format($maxRows) }} baris pertama daripada {{ number_format($total) }}. Gunakan eksport Excel untuk senarai penuh.</p>
    @endif
</body>
</html>
