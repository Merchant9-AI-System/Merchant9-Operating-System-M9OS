<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Senarai Restock</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 12px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1c1917; background: #fff; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #1c1917; break-after: avoid; }
        .meta { color: #57534e; margin-bottom: 8px; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 12px; border: 1px solid #a8a29e; border-radius: 6px; background: #fafaf9; color: inherit; text-decoration: none; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #d6d3d1; text-align: left; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #57534e; border-bottom: 1px solid #1c1917; }
        tr { break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { font-weight: 600; white-space: nowrap; }
        .muted { color: #57534e; }
        .qty { font-size: 15px; font-weight: 700; }
        .low { color: #b91c1c; font-weight: 600; }
        .thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; }
        ul { margin: 0; padding-left: 14px; }
        .supplier-table { margin-top: 6px; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            @page { size: A4 landscape; margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
        <a href="{{ route('back-office-actions.restock.print', ['group' => 'category']) }}">Ikut Kategori</a>
        <a href="{{ route('back-office-actions.restock.print', ['group' => 'branch']) }}">Ikut Cawangan</a>
        @if ($withSuppliers)
            <a href="{{ route('back-office-actions.restock.print', ['group' => 'supplier']) }}">Ikut Supplier</a>
        @endif
    </div>

    <h1>Senarai Restock</h1>
    <p class="meta">Dikumpul ikut {{ $groupLabel }} &middot; {{ now()->format('d/m/Y H:i') }} &middot; {{ $generatedBy }}</p>

    @forelse ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Design</th>
                    <th>Permintaan cawangan (saiz / berat / qty)</th>
                    <th>Stok semasa</th>
                    <th class="num">Jualan 7H / 30H</th>
                    @if ($withSuppliers)
                        <th>Kod supplier teratas</th>
                    @endif
                    <th class="num">Qty order</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($section['items'] as $item)
                    <tr>
                        <td>
                            @if ($item['image_url'])
                                <img class="thumb" src="{{ $item['image_url'] }}" alt="">
                            @endif
                        </td>
                        <td>
                            <div class="code">{{ $item['internal_code'] }}</div>
                            <div class="muted">{{ $item['description'] }}@if ($item['nickname']) &middot; &quot;{{ $item['nickname'] }}&quot;@endif</div>
                            @if ($item['category_name'])<div class="muted">{{ $item['category_name'] }}</div>@endif
                        </td>
                        <td>
                            @if (count($item['requests']))
                                <ul>
                                    @foreach ($item['requests'] as $r)
                                        <li>{{ \App\Support\RestockListBuilder::describeRequest($r) }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                        <td>
                            @foreach ($item['stock'] as $s)
                                <span class="{{ $s['stock'] <= 1 ? 'low' : '' }}">{{ $s['store_code'] }} {{ $s['stock'] }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </td>
                        <td class="num">{{ $item['sold_7d'] }} / {{ $item['sold_30d'] }}</td>
                        @if ($withSuppliers)
                            <td>
                                @foreach ($item['suppliers'] as $v)
                                    <div>{{ $v['vendor_code'] }} <span class="muted">({{ $v['sold'] }})</span></div>
                                @endforeach
                            </td>
                        @endif
                        <td class="num qty">{{ $item['qty_to_order'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="muted">Senarai Restock kosong.</p>
    @endforelse

    @if ($withSuppliers && count($suppliers))
        <h2>Best Supplier to Meet</h2>
        <table class="supplier-table">
            <thead>
                <tr>
                    <th>Kod supplier</th>
                    <th>Kod design</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($suppliers as $v)
                    <tr>
                        <td class="code">{{ $v['vendor_code'] }}</td>
                        <td>{{ implode(', ', $v['codes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
