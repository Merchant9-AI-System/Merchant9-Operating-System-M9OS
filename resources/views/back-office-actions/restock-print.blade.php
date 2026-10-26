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
        .brand { display: block; height: 32px; width: auto; margin: 0 0 10px; }
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
        .got { font-weight: 700; }
        .warn { font-weight: 700; text-decoration: underline; }
        .chip { display: inline-block; margin: 0 6px 2px 0; white-space: nowrap; }
        .note { font-size: 10px; color: #57534e; }
        .badge { display: inline-block; margin-left: 4px; padding: 0 6px; border: 1px solid #a8a29e; border-radius: 999px; font-size: 10px; font-weight: 600; white-space: nowrap; }
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
        <a href="{{ route('back-office-actions.restock.print', ['group' => 'category', 'days' => $supplierDays]) }}">Ikut Kategori</a>
        <a href="{{ route('back-office-actions.restock.print', ['group' => 'branch', 'days' => $supplierDays]) }}">Ikut Cawangan</a>
        @if ($withSuppliers)
            <a href="{{ route('back-office-actions.restock.print', ['group' => 'supplier', 'days' => $supplierDays]) }}">Ikut Supplier</a>
        @endif
    </div>

    <img class="brand" src="{{ asset('images/brand/merchant9-lockup-horizontal.png') }}" alt="Merchant9 Operating Systems">
    <h1>Senarai Restock</h1>
    <p class="meta">Dikumpul ikut {{ $groupLabel }} &middot; {{ now()->format('d/m/Y H:i') }} &middot; {{ $generatedBy }}</p>
    <p class="note">Agihan qty order ke setiap cawangan (Restock sahaja): cawangan yang request dahulu, kemudian isi ke paras. <strong>Tebal</strong> = cawangan menerima unit; <span class="warn">!</span> = request belum dipenuhi (qty diubah sendiri).</p>

    @forelse ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Design</th>
                    <th>Permintaan cawangan (saiz / berat / qty)</th>
                    <th>{!! $group === 'branch' ? 'Stok cawangan ini &rarr; selepas order' : 'Stok semasa &rarr; selepas order' !!}</th>
                    <th class="num">Paras</th>
                    @if ($withLatestSales)
                        <th>Jualan terkini</th>
                    @endif
                    @if ($withSuppliers)
                        <th>Kod supplier teratas (terjual {{ $supplierDays }} hari)</th>
                    @endif
                    <th class="num">Qty order</th>
                    @if ($group === 'branch')
                        <th class="num">Cawangan ini dapat</th>
                    @endif
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
                            <div class="muted">{{ $item['description'] }}@if ($item['nickname']) &middot; {{ $item['nickname'] }}@endif</div>
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
                            @if ($group === 'branch')
                                <span class="{{ ($item['section_alloc'] ?? 0) > 0 ? 'got' : '' }}">{{ \App\Support\RestockListBuilder::describeSectionStock($item) }}</span>
                            @else
                            @foreach (\App\Support\RestockListBuilder::branchRows($item) as $s)
                                <span class="chip {{ $s['alloc'] > 0 ? 'got' : ($s['stock'] <= 1 ? 'low' : '') }}">
                                    {{ $s['store_code'] }} {{ $s['stock'] }}@if ($s['alloc'] > 0) &rarr; {{ $s['after'] }} (+{{ $s['alloc'] }})@endif
                                    @if ($s['short'])<span class="warn">! request {{ $s['requested'] }}, dapat {{ $s['alloc'] }}</span>@endif
                                </span>
                            @endforeach
                            @endif
                        </td>
                        <td class="num">{{ \App\Support\RestockListBuilder::describeParas($item) }}</td>
                        @if ($withLatestSales)
                            <td>
                                @if (! empty($item['last_sale']))
                                    {{ \Illuminate\Support\Carbon::parse($item['last_sale']['date'])->format('d/m/Y') }}
                                    <div class="muted">
                                        {{ $item['last_sale']['days_ago'] === 0 ? 'hari ini' : ($item['last_sale']['days_ago'] === 1 ? 'semalam' : $item['last_sale']['days_ago'].' hari lepas') }}
                                        &middot; {{ $item['last_sale']['store'] }}
                                    </div>
                                    <div class="muted">{{ $item['last_sale']['sold_30d'] }} unit terjual (30 hari)</div>
                                @else
                                    <span class="muted">Belum pernah terjual</span>
                                @endif
                            </td>
                        @endif
                        @if ($withSuppliers)
                            <td>
                                @foreach ($item['suppliers'] as $v)
                                    <div>{{ $v['vendor_code'] }}<span class="badge">{{ $v['sold'] }} terjual</span></div>
                                @endforeach
                            </td>
                        @endif
                        <td class="num qty">{{ $item['qty_to_order'] }}</td>
                        @if ($group === 'branch')
                            <td class="num qty">{{ $item['section_alloc'] ?? 0 }}</td>
                        @endif
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
                    <th class="num">Terjual ({{ $supplierDays }} hari)</th>
                    <th>Kod design</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($suppliers as $v)
                    <tr>
                        <td class="code">{{ $v['vendor_code'] }}</td>
                        <td class="num">{{ $v['sold'] }}</td>
                        <td>{{ implode(', ', $v['codes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
