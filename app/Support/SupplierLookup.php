<?php

namespace App\Support;

use App\Enums\JemisysInventoryStatus;
use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data halaman "Supplier Lookup" (rujuk SupplierLookupController): sell-through setiap design daripada SATU supplier (vendor) -
 * piece yg DIAMBIL (stok masuk) dlm tempoh yg dipilih.
 *
 *   Total Amik/Buy   = piece supplier itu yg PurchDate-nya dlm tempoh
 *   Total Sold       = daripada piece itu, Status '0' (Sold) atau '9' (SoldClosed) - sama dgn katalog supplier
 *   Total Available  = Buy - Sold (piece yg belum terjual: tersedia, transit, loan dll)
 *   Percentage       = Sold / Buy x 100
 *
 * Senarai TIDAK dipaparkan sehingga pengguna memilih supplier (autocomplete kod/nama vendor -> suggestions()). Server hanya
 * menghantar SEMUA design supplier itu SEKALI (designs(): kod + [Amik, Sold] setiap cawangan) bagi supplier + tempoh; tapisan
 * cawangan, susunan, halaman & pengiraan Available/Percentage dibuat di PELAYAR (resources/js/lib/supplierLookup.ts), jadi
 * mengubahnya serta-merta tanpa permintaan rangkaian. Nickname & imej TIDAK dihantar bersama (query merentas semua design diukur
 * ~50 saat) - klien meminta hanya utk baris yg kelihatan sehalaman (details()).
 *
 * Eksport Excel/PDF (rujuk SupplierLookupController::export()/print()) mencerminkan apa yg pengguna nampak: pelayar menghantar supplier,
 * tempoh, cawangan & susunan, dan server membina baris yg SAMA (exportRows() = salinan PHP bagi rollUp/sortRows dlm lib/supplierLookup.ts -
 * ubah KEDUA-DUA). Tajuk lajur = COLUMN_LABELS (salinan pemalar TS dgn nama sama).
 *
 * Agregat (design x cawangan x supplier, ikut StoreCode semasa piece) bagi setiap tempoh dikira SEKALI & dipecah ikut supplier dlm
 * cache (kunci mengandungi masa segerak cermin terakhir, jadi sah sehingga segerak berikutnya & dipanaskan oleh
 * `supplier-lookup:warm`).
 */
final class SupplierLookup
{
    /** Pilihan tempoh (hari) - had atas sama dgn katalog supplier. */
    public const PERIOD_DAYS = [30, 90, 180, 365, 740];

    public const DEFAULT_DAYS = 90;

    /** Status piece terjual (TblInventory: '0' Sold, '9' SoldClosed). */
    public const SOLD_STATUSES = ['0', '9'];

    /** @var array<int, string> */
    public const SORTS = ['code', 'requested', 'buy', 'sold', 'available', 'percent'];

    /** Paling banyak kunci susunan serentak (salinan MAX_SORTS dlm resources/js/lib/supplierLookup.ts). */
    public const MAX_SORTS = 5;

    /**
     * Tajuk lajur jadual - SALINAN COLUMN_LABELS dlm resources/js/lib/supplierLookup.ts (eksport memakai tajuk yg sebijik sama; ujian
     * memastikan kedua-duanya sama). `store` hanya wujud dlm mod cawangan.
     *
     * @var array<string, string>
     */
    public const COLUMN_LABELS = [
        'image' => 'Image',
        'code' => 'Design Code',
        'store' => 'Cawangan',
        'requested' => 'Branch Requested',
        'buy' => 'Total Ambil/Buy',
        'sold' => 'Total Sold',
        'available' => 'Total Available',
        'percent' => 'Percentage',
    ];

    /** Paling banyak baris pd cetakan PDF (setiap baris ada imej) - Excel tiada had. */
    public const PRINT_MAX_ROWS = 1000;

    /** Saiz kumpulan kod bagi query butiran utk eksport (jauh lebih besar drpd satu halaman jadual). */
    private const EXPORT_DETAIL_CHUNK = 500;

    /**
     * Paling banyak piece dihantar utk halaman butiran design (terkini dahulu; jumlah sebenar dipaparkan berasingan). Susunan jadual piece
     * dibuat di PELAYAR atas senarai ini, jadi had mesti menampung design terbesar (~2,900 piece) supaya susunan betul utk SEMUA piece.
     */
    public const DESIGN_PIECE_LIMIT = 3000;

    /** Paling banyak cadangan supplier dlm autocomplete. */
    public const SUGGESTION_LIMIT = 8;

    /** Paling banyak kod design bagi satu permintaan details() (satu halaman jadual = 25). */
    public const MAX_DETAIL_CODES = 60;

    /** Cermin hanya berubah bila disegerak (harian), & kunci cache mengandungi masa segerak - jadi TTL panjang selamat. */
    private const CACHE_MINUTES = 1440;

    /** Index covering (PurchDate, InternalCode, StoreCode, Status, VendorCode) - rujuk migrasi dgn nama yg sama. */
    private const COVERING_INDEX = 'idx_mirror_supplier_lookup_covering';

    /** Index covering jualan (SalesDate, InternalCode, StoreCode, VendorCode, Status) - rujuk migrasi dgn nama yg sama. */
    private const SALES_INDEX = 'idx_mirror_supplier_sales_covering';

    /** Index covering imej (InternalCode, image_source, image_url) & nickname (InternalCode, nickname) utk butiran. */
    private const IMAGE_INDEX = 'idx_mirror_board_image_ref_covering';

    private const NICKNAME_INDEX = 'idx_mirror_board_nickname_covering';

    /**
     * @param  Carbon  $from  tempoh STOCK IN (PurchDate) - Total Amik/Buy
     * @param  Carbon  $salesFrom  tempoh JUALAN (SalesDate) - Total Sold
     */
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly Carbon $salesFrom,
        private readonly Carbon $salesTo,
    ) {}

    /** Tempoh stock in $days hari terakhir & tempoh jualan $salesDays hari terakhir (lalai = sama dgn $days). */
    public static function forDays(int $days, ?int $salesDays = null): self
    {
        $to = now()->endOfDay();
        $salesDays ??= $days;

        return new self(
            $to->copy()->subDays($days - 1)->startOfDay(),
            $to,
            $to->copy()->subDays($salesDays - 1)->startOfDay(),
            $to->copy(),
        );
    }

    public static function normalizeDays(mixed $days): int
    {
        return in_array((int) $days, self::PERIOD_DAYS, true) ? (int) $days : self::DEFAULT_DAYS;
    }

    public static function normalizeSort(mixed $sort): string
    {
        return in_array($sort, self::SORTS, true) ? (string) $sort : 'code';
    }

    public static function normalizeDirection(mixed $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Susunan berbilang drpd parameter eksport: "percent:desc,buy:desc" (kunci pertama paling utama). Bentuk lama sort=percent&dir=desc
     * masih diterima. Kunci tak sah & berulang dibuang, dihadkan MAX_SORTS; kosong/tak sah = kod menaik.
     *
     * @return list<array{key: string, dir: string}>
     */
    public static function parseSorts(mixed $sort, mixed $direction = null): array
    {
        $sorts = [];

        foreach (explode(',', is_string($sort) ? $sort : '') as $part) {
            [$key, $dir] = array_pad(explode(':', trim($part), 2), 2, null);

            if (in_array($key, self::SORTS, true) && ! isset($sorts[$key])) {
                // Bentuk lama: arah datang dr parameter `dir` berasingan (kunci tunggal tanpa ":").
                $sorts[$key] = ['key' => $key, 'dir' => self::normalizeDirection($dir ?? $direction)];
            }
        }

        return $sorts === [] ? [['key' => 'code', 'dir' => 'asc']] : array_slice(array_values($sorts), 0, self::MAX_SORTS);
    }

    /**
     * Semua design SATU supplier: kod + setiap cawangan => [Amik, Sold, Available]. Disusun ikut kod (klien yg menyusun semula).
     *
     * DUA tempoh bebas: Amik = piece yg PurchDate-nya dlm tempoh STOCK IN; Sold = SEMUA piece supplier itu yg terjual (Status 0/9) dgn
     * SalesDate dlm tempoh JUALAN (walaupun masuk sebelum tempoh stock in); Available = baki batch stock in yg belum terjual (Amik tolak
     * piece batch itu yg sudah terjual - bila-bila masa), jadi tak negatif. Design yg hanya ada jualan (tiada stock in dlm tempoh) muncul
     * dgn Amik 0.
     *
     * Setiap design turut membawa `category` (kod kategori, null jika tiada) utk penapis kategori di pelayar.
     *
     * @return list<array{code: string, category: ?string, stores: array<string, array{0: int, 1: int, 2: int}>}>
     */
    public function designs(string $supplier): array
    {
        $designs = [];
        $categories = self::categoryByDesign();

        // Kod mentah berbeza selepas trim (cth. aksara TAB) atau hanya berbeza huruf besar/kecil (cermin ada 'WEB' & 'web') boleh
        // jatuh pd design & cawangan yg sama - kod cawangan diseragamkan kpd HURUF BESAR & dijumlahkan.
        $add = function (array $group, string $key) use (&$designs): void {
            $store = strtoupper($group['store']);
            $designs[$group['code']]['code'] = $group['code'];
            $entry = $designs[$group['code']]['stores'][$store] ?? ['buy' => 0, 'batch_sold' => 0, 'sold' => 0];

            if ($key === 'stock_in') {
                $entry['buy'] += $group['buy'];
                $entry['batch_sold'] += $group['sold'];
            } else {
                $entry['sold'] += $group['sold'];
            }

            $designs[$group['code']]['stores'][$store] = $entry;
        };

        foreach ($this->groups($supplier) as $group) {
            $add($group, 'stock_in');
        }

        foreach ($this->salesGroups($supplier) as $group) {
            $add($group, 'sales');
        }

        ksort($designs, SORT_STRING);

        // Cawangan juga disusun (kekunci) - susunan tetap, tidak bergantung pd susunan baris DB.
        return array_values(array_map(function (array $design) use ($categories): array {
            ksort($design['stores'], SORT_STRING);
            $design['category'] = $categories[$design['code']] ?? null;
            $design['stores'] = array_map(
                fn (array $entry): array => [$entry['buy'], $entry['sold'], max(0, $entry['buy'] - $entry['batch_sold'])],
                $design['stores'],
            );

            return $design;
        }, $designs));
    }

    /**
     * Nickname, imej & gambar rujukan bagi sekumpulan kod (satu halaman jadual). Setiap kod diminta ada dlm hasil (null jika tiada
     * maklumat) supaya klien tahu ia sudah dimuat.
     *
     * @param  list<string>  $codes
     * @return array<string, array{nickname: ?string, image_url: ?string, image_reference: bool}>
     */
    public static function details(array $codes): array
    {
        return self::detailsFor(
            collect($codes)
                ->filter(fn ($code) => is_string($code))
                ->map(fn (string $code) => mb_substr(trim($code), 0, 20))
                ->filter()
                ->unique()
                ->take(self::MAX_DETAIL_CODES)
                ->values()
                ->all(),
            self::MAX_DETAIL_CODES,
        );
    }

    /**
     * Butiran bagi kod yg sudah bersih, dlm kumpulan `$chunk` kod setiap query.
     *
     * @param  list<string>  $codes
     * @return array<string, array{nickname: ?string, image_url: ?string, image_reference: bool}>
     */
    private static function detailsFor(array $codes, int $chunk): array
    {
        if ($codes === []) {
            return [];
        }

        // DUA query ber-index covering (imej: InternalCode, image_source, image_url; nickname: InternalCode, nickname) - dijawab drpd
        // index sahaja, ~3x lebih pantas drpd satu query MAX() merentas lajur lebar jadual (diukur 5.4s -> 1.8s utk 500 kod).
        $images = [];
        $nicknames = [];

        foreach (array_chunk($codes, $chunk) as $codesChunk) {
            DB::table(DB::raw('jemisys_inventory_mirror'.self::indexHint(self::IMAGE_INDEX)))
                ->whereIn('InternalCode', $codesChunk)
                ->selectRaw('InternalCode, MAX(image_url) as image_url, MAX(image_source) as image_source')
                ->groupBy('InternalCode')
                ->get()
                ->each(function ($row) use (&$images): void {
                    $images[trim((string) $row->InternalCode)] = $row;
                });

            DB::table(DB::raw('jemisys_inventory_mirror'.self::indexHint(self::NICKNAME_INDEX)))
                ->whereIn('InternalCode', $codesChunk)
                ->selectRaw('InternalCode, MAX(nickname) as nickname')
                ->groupBy('InternalCode')
                ->get()
                ->each(function ($row) use (&$nicknames): void {
                    $nicknames[trim((string) $row->InternalCode)] = $row->nickname;
                });
        }

        return collect($codes)->mapWithKeys(function (string $code) use ($images, $nicknames): array {
            $image = $images[$code] ?? null;
            $hasImage = $image !== null && filled($image->image_url);

            return [$code => [
                'nickname' => filled($nicknames[$code] ?? null) ? trim((string) $nicknames[$code]) : null,
                'image_url' => $hasImage ? (string) $image->image_url : null,
                'image_reference' => $hasImage && $image->image_source === DesignFamily::SOURCE_NEAREST,
            ]];
        })->all();
    }

    /**
     * Baris eksport = baris yg SAMA dgn jadual di pelayar: roll-up (jumlah semua cawangan, atau satu baris setiap design setiap cawangan
     * dipilih), disusun, kemudian dilekatkan nickname/imej (semua baris, bukan satu halaman).
     *
     * @param  list<string>  $branches
     * @param  list<array{key: string, dir: string}>  $sorts  rujuk parseSorts()
     * @param  list<string>  $categories  kod kategori yg dipilih (kosong = semua)
     * @return list<array{code: string, store: ?string, buy: int, sold: int, available: int, percent: float, requested: int, branches: list<array{store: string, buy: int, sold: int, available: int, requested: int}>, nickname: ?string, image_url: ?string, image_reference: bool}>
     */
    public function exportRows(string $supplier, array $branches, array $sorts, array $categories = []): array
    {
        $designs = $this->designs($supplier);

        if ($categories !== []) {
            $wanted = array_flip(array_map('strtoupper', $categories));
            $designs = array_values(array_filter($designs, fn (array $design): bool => isset($wanted[strtoupper((string) $design['category'])])));
        }

        $rows = self::sorted(self::rollUp($designs, $branches, self::demandFor($designs)), $sorts);
        $details = self::detailsFor(array_values(array_unique(array_column($rows, 'code'))), self::EXPORT_DETAIL_CHUNK);

        return array_map(fn (array $row): array => $row + ($details[$row['code']] ?? ['nickname' => null, 'image_url' => null, 'image_reference' => false]), $rows);
    }

    /**
     * Salinan PHP bagi rollUp() dlm lib/supplierLookup.ts. Tanpa cawangan: satu baris setiap design (jumlah semua cawangan) + `branches`
     * (pecahan ikut cawangan utk popover di skrin). Dgn cawangan: satu baris setiap design setiap cawangan yg dipilih, hanya yg ada piece diambil.
     *
     * `requested` (Branch Requested) = permintaan cawangan yg belum selesai (rujuk demandFor()): tanpa cawangan = jumlah SEMUA cawangan yg
     * minta (termasuk yg tak pernah ambil piece drpd supplier ini - muncul dlm `branches` utk popover); mod cawangan = permintaan
     * cawangan baris itu sahaja (cawangan yg minta tapi tiada piece diambil tidak mendapat baris sendiri).
     *
     * @param  list<array{code: string, stores: array<string, array{0: int, 1: int, 2: int}>}>  $designs
     * @param  list<string>  $branches
     * @param  array<string, array<string, int>>  $demand  kod => [cawangan => qty] (demandFor())
     * @return list<array{code: string, store: ?string, buy: int, sold: int, available: int, percent: float, requested: int, branches: list<array{store: string, buy: int, sold: int, available: int, requested: int}>}>
     */
    public static function rollUp(array $designs, array $branches, array $demand = []): array
    {
        $rows = [];

        foreach ($designs as $design) {
            $requested = $demand[$design['code']] ?? [];

            if ($branches === []) {
                $stores = array_keys($design['stores'] + $requested);
                sort($stores, SORT_STRING);

                $breakdown = [];

                foreach ($stores as $store) {
                    [$storeBuy, $storeSold, $storeAvailable] = $design['stores'][$store] ?? [0, 0, 0];
                    $breakdown[] = ['store' => (string) $store, 'buy' => $storeBuy, 'sold' => $storeSold, 'available' => $storeAvailable, 'requested' => $requested[$store] ?? 0];
                }

                $rows[] = self::makeRow(
                    $design['code'],
                    null,
                    array_sum(array_column($design['stores'], 0)),
                    array_sum(array_column($design['stores'], 1)),
                    array_sum(array_column($design['stores'], 2)),
                    array_sum($requested),
                    $breakdown,
                );

                continue;
            }

            foreach ($branches as $branch) {
                if (isset($design['stores'][$branch])) {
                    $rows[] = self::makeRow($design['code'], $branch, $design['stores'][$branch][0], $design['stores'][$branch][1], $design['stores'][$branch][2], $requested[$branch] ?? 0);
                }
            }
        }

        return $rows;
    }

    /**
     * Branch Requested: baki permintaan cawangan (jadual branch_demand_request_lines) bagi design supplier ini - SATU query kecil, SENTIASA
     * segar (tidak dicache bersama agregat berat) supaya mengikut status BO semasa. Definisi SAMA dgn Back Office Actions (rujuk
     * BackOfficeActionsAdvisor::RESTOCK_REQUEST_STATUSES): peringkat yg BELUM selesai sahaja (Requested, Stok Kritikal, Special Request,
     * Listed/Noted, Order, Rearrange) - Dah Order/Restock/Delivery & Item Not Available tak dikira; line berstatus done_at, Rejected atau
     * request Cancelled juga tak dikira. Kuantiti = qty_requested; cawangan = store_code request (termasuk WEB). Line tanpa internal_code
     * (web/upload yg belum dipadankan) tak boleh dipadankan dgn design.
     *
     * @param  list<array{code: string, stores: array<string, array{0: int, 1: int, 2: int}>}>  $designs
     * @return array<string, array<string, int>> kod design => [cawangan => qty]
     */
    public static function demandFor(array $designs): array
    {
        if ($designs === []) {
            return [];
        }

        $rows = DB::table('branch_demand_request_lines as lines')
            ->join('branch_demand_requests as requests', 'requests.id', '=', 'lines.branch_demand_request_id')
            ->whereIn('lines.fulfillment_status', BackOfficeActionsAdvisor::RESTOCK_REQUEST_STATUSES)
            ->whereNull('lines.done_at')
            ->where('lines.line_status', '!=', BranchDemandRequestLine::STATUS_REJECTED)
            ->where('requests.status', '!=', BranchDemandRequest::STATUS_CANCELLED)
            ->whereRaw("TRIM(COALESCE(lines.internal_code, '')) <> ''")
            ->selectRaw('TRIM(lines.internal_code) as code, TRIM(requests.store_code) as store, SUM(lines.qty_requested) as qty')
            ->groupBy('code', 'store')
            ->get();

        $wanted = array_flip(array_column($designs, 'code'));
        $demand = [];

        foreach ($rows as $row) {
            if (isset($wanted[$row->code])) {
                // Kod cawangan diseragamkan kpd HURUF BESAR (sama dgn designs()) - 'WEB' & 'web' ialah cawangan yg sama.
                $store = strtoupper($row->store);
                $demand[$row->code][$store] = ($demand[$row->code][$store] ?? 0) + (int) $row->qty;
            }
        }

        return $demand;
    }

    /**
     * Salinan PHP bagi sortRows() dlm lib/supplierLookup.ts: susunan berbilang (kunci pertama dahulu, seri dipecahkan kunci seterusnya),
     * akhir sekali ikut kod kemudian cawangan (perbandingan bait).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{key: string, dir: string}>  $sorts
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $rows, array $sorts): array
    {
        usort($rows, function (array $a, array $b) use ($sorts): int {
            foreach ($sorts as ['key' => $key, 'dir' => $dir]) {
                $primary = $key === 'code' ? strcmp((string) $a['code'], (string) $b['code']) : $a[$key] <=> $b[$key];

                if ($primary !== 0) {
                    return $dir === 'desc' ? -$primary : $primary;
                }
            }

            return strcmp((string) $a['code'], (string) $b['code']) ?: strcmp((string) $a['store'], (string) $b['store']);
        });

        return $rows;
    }

    /**
     * @param  list<array{store: string, buy: int, sold: int, available: int, requested: int}>  $breakdown
     * @return array{code: string, store: ?string, buy: int, sold: int, available: int, percent: float, requested: int, branches: list<array{store: string, buy: int, sold: int, available: int, requested: int}>}
     */
    private static function makeRow(string $code, ?string $store, int $buy, int $sold, int $available, int $requested, array $breakdown = []): array
    {
        return ['code' => $code, 'store' => $store, 'buy' => $buy, 'sold' => $sold, 'available' => $available, 'percent' => $buy > 0 ? (float) ($sold / $buy * 100) : 0.0, 'requested' => $requested, 'branches' => $breakdown];
    }

    /**
     * Kategori setiap design (kod kategori, ditrim; kod kosong = tiada) merentas SELURUH cermin - satu imbasan index covering
     * (CategoryCode, InternalCode) ~3 saat, di-cache (kunci mengandungi masa segerak cermin). Design yg tersenarai dlm >1 kategori
     * (sangat jarang) dapat kategori dgn kod terbesar supaya konsisten.
     *
     * @return array<string, string> kod design => kod kategori
     */
    public static function categoryByDesign(): array
    {
        $key = 'supplier-lookup:categories:v1:'.md5((string) InventoryPiece::query()->max('synced_at'));

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function (): array {
            $map = [];

            DB::table((new InventoryPiece)->getTable())
                ->whereNotNull('InternalCode')
                ->where('InternalCode', '!=', '')
                ->selectRaw('TRIM(InternalCode) as code, MAX(TRIM(CategoryCode)) as category')
                ->groupBy('code')
                ->orderBy('code')
                ->get()
                ->each(function (object $row) use (&$map): void {
                    if (filled($row->category)) {
                        $map[(string) $row->code] = (string) $row->category;
                    }
                });

            return $map;
        });
    }

    /**
     * Nama kategori (kod => keterangan) utk kod yg diberi; keterangan kosong jatuh balik ke kod.
     *
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    public static function categoryNames(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $descriptions = Category::query()->get(['CategoryCode', 'Description'])
            ->mapWithKeys(fn (Category $category): array => [trim((string) $category->CategoryCode) => trim((string) $category->Description)]);

        $names = [];

        foreach ($codes as $code) {
            $names[$code] = filled($descriptions[$code] ?? null) ? $descriptions[$code] : $code;
        }

        ksort($names, SORT_STRING);

        return $names;
    }

    /**
     * Butiran SATU design utk halaman butiran (semua supplier, semua cawangan, semua masa): maklumat asas (nickname, kategori, saiz, berat,
     * supplier), stok & jualan mengikut cawangan (+ Branch Requested), dan senarai piece (terkini dahulu, dihadkan DESIGN_PIECE_LIMIT).
     * Gambar TIDAK dikira di sini (kikis laman web boleh lambat) - rujuk designImages(). Null jika kod tiada satu piece pun.
     *
     * @return array{
     *     code: string, nickname: ?string, description: ?string, category: ?array{code: string, name: string},
     *     sizes: list<string>, sizes_available: list<string>, weight: array{min: float, max: float}, length: ?string, vendors: list<array{code: string, name: string}>,
     *     suppliers: list<array{code: string, name: string, buy: int, sold: int, available: int}>,
     *     totals: array{pieces: int, on_hand: int, sold: int}, branches: list<array{store: string, pieces: int, on_hand: int, sold: int, requested: int}>,
     *     pieces: list<array{inventory_code: string, store: string, status: string, status_label: string, vendor: string, size: ?string, weight: float, purchased_at: ?string, sold_at: ?string, purchased_on: ?string, sold_on: ?string}>,
     *     pieces_total: int
     * }|null
     */
    public static function designDetail(string $code, ?int $pieceLimit = null): ?array
    {
        $code = trim($code);
        $table = (new InventoryPiece)->getTable();

        if ($code === '') {
            return null;
        }

        $soldList = "'".implode("','", self::SOLD_STATUSES)."'";

        $meta = DB::table($table)->where('InternalCode', $code)
            ->selectRaw('COUNT(*) as pieces, MAX(nickname) as nickname, MAX(Description) as description, MAX(TRIM(CategoryCode)) as category, MIN(GoldWeight) as w_min, MAX(GoldWeight) as w_max, MAX(JewelLength) as length')
            ->first();

        if ($meta === null || (int) $meta->pieces === 0) {
            return null;
        }

        $branches = [];

        foreach (DB::table($table)->where('InternalCode', $code)
            ->selectRaw("StoreCode, COUNT(*) as pieces, SUM(CASE WHEN QtyOnHand = 1 THEN 1 ELSE 0 END) as on_hand, SUM(CASE WHEN Status IN ({$soldList}) THEN 1 ELSE 0 END) as sold")
            ->groupBy('StoreCode')->get() as $row) {
            // 'WEB' & 'web' ialah cawangan yg sama (rujuk designs()).
            $store = strtoupper(trim((string) $row->StoreCode));
            $entry = $branches[$store] ?? ['store' => $store, 'pieces' => 0, 'on_hand' => 0, 'sold' => 0, 'requested' => 0];
            $entry['pieces'] += (int) $row->pieces;
            $entry['on_hand'] += (int) $row->on_hand;
            $entry['sold'] += (int) $row->sold;
            $branches[$store] = $entry;
        }

        foreach (self::demandFor([['code' => $code, 'stores' => []]])[$code] ?? [] as $store => $qty) {
            $branches[$store] ??= ['store' => $store, 'pieces' => 0, 'on_hand' => 0, 'sold' => 0, 'requested' => 0];
            $branches[$store]['requested'] = $qty;
        }

        ksort($branches, SORT_STRING);

        $vendorCodes = DB::table($table)->where('InternalCode', $code)->selectRaw('DISTINCT TRIM(VendorCode) as vendor')->pluck('vendor')
            ->filter(fn ($vendor) => filled($vendor) && $vendor !== '.')->values();
        $vendorNames = Vendor::query()->whereIn('VendorCode', $vendorCodes)->get(['VendorCode', 'Description'])
            ->mapWithKeys(fn (Vendor $vendor): array => [trim((string) $vendor->VendorCode) => trim((string) $vendor->Description)]);

        // Senarai supplier yg ada design ini (semua masa): Ambil = semua piece daripadanya, Sold = yg terjual (Status 0/9), Available = Ambil tolak Sold.
        $suppliers = DB::table($table)->where('InternalCode', $code)
            ->selectRaw("TRIM(VendorCode) as vendor, COUNT(*) as buy, SUM(CASE WHEN Status IN ({$soldList}) THEN 1 ELSE 0 END) as sold")
            ->groupBy('vendor')->orderByDesc('buy')->orderBy('vendor')->get()
            ->filter(fn (object $row) => filled($row->vendor) && $row->vendor !== '.')
            ->map(fn (object $row): array => [
                'code' => (string) $row->vendor,
                'name' => filled($vendorNames[$row->vendor] ?? null) ? $vendorNames[$row->vendor] : (string) $row->vendor,
                'buy' => (int) $row->buy,
                'sold' => (int) $row->sold,
                'available' => (int) $row->buy - (int) $row->sold,
            ])->values()->all();

        // Saiz: SEMUA yg pernah di-stock in (`sizes`) & yg ADA STOK sekarang (`sizes_available`, QtyOnHand = 1) - susunan nombor semula jadi (7, 7.5, 8, ...).
        $sizesWhere = fn (bool $onHandOnly): array => DB::table($table)->where('InternalCode', $code)->whereNotNull('JewelSize')
            ->when($onHandOnly, fn ($query) => $query->where('QtyOnHand', 1))
            ->selectRaw('DISTINCT JewelSize as size')->pluck('size')
            ->map(fn ($size) => RestockAnalysisCalculator::sizeLabel($size))->reject(fn (string $size) => $size === '(tiada)')
            ->unique()->sort(SORT_NATURAL)->values()->all();
        $sizes = $sizesWhere(false);
        $sizesAvailable = $sizesWhere(true);

        $categoryCode = filled($meta->category) ? (string) $meta->category : null;

        $pieces = DB::table($table)->where('InternalCode', $code)
            ->orderByDesc('PurchDate')->orderBy('InventoryCode')->limit($pieceLimit ?? self::DESIGN_PIECE_LIMIT)
            ->get(['InventoryCode', 'StoreCode', 'Status', 'VendorCode', 'GoldWeight', 'JewelSize', 'PurchDate', 'SalesDate'])
            ->map(fn (object $piece): array => [
                'inventory_code' => trim((string) $piece->InventoryCode),
                'store' => strtoupper(trim((string) $piece->StoreCode)),
                'status' => trim((string) $piece->Status),
                'status_label' => JemisysInventoryStatus::labelFor(trim((string) $piece->Status)),
                'vendor' => trim((string) $piece->VendorCode),
                'size' => ($size = RestockAnalysisCalculator::sizeLabel($piece->JewelSize)) === '(tiada)' ? null : $size,
                'weight' => round((float) $piece->GoldWeight, 2),
                'purchased_at' => $piece->PurchDate ? Carbon::parse($piece->PurchDate)->format('d/m/Y') : null,
                'sold_at' => $piece->SalesDate ? Carbon::parse($piece->SalesDate)->format('d/m/Y') : null,
                // Tarikh ISO utk susunan di pelayar (d/m/Y tak boleh disusun sbg teks).
                'purchased_on' => $piece->PurchDate ? Carbon::parse($piece->PurchDate)->toDateString() : null,
                'sold_on' => $piece->SalesDate ? Carbon::parse($piece->SalesDate)->toDateString() : null,
            ])->all();

        return [
            'code' => $code,
            'nickname' => filled($meta->nickname) ? trim((string) $meta->nickname) : null,
            'description' => filled($meta->description) ? trim((string) $meta->description) : null,
            'category' => $categoryCode === null ? null : ['code' => $categoryCode, 'name' => self::categoryNames([$categoryCode])[$categoryCode] ?? $categoryCode],
            'sizes' => $sizes,
            'sizes_available' => $sizesAvailable,
            'weight' => ['min' => round((float) $meta->w_min, 2), 'max' => round((float) $meta->w_max, 2)],
            'length' => filled($meta->length) && (float) $meta->length > 0 ? RestockAnalysisCalculator::sizeLabel($meta->length) : null,
            'suppliers' => $suppliers,
            'vendors' => $vendorCodes->map(fn (string $vendor): array => ['code' => $vendor, 'name' => filled($vendorNames[$vendor] ?? null) ? $vendorNames[$vendor] : $vendor])->all(),
            'totals' => [
                'pieces' => (int) $meta->pieces,
                'on_hand' => array_sum(array_column($branches, 'on_hand')),
                'sold' => array_sum(array_column($branches, 'sold')),
            ],
            'branches' => array_values($branches),
            'pieces' => $pieces,
            'pieces_total' => (int) $meta->pieces,
        ];
    }

    /**
     * Semua gambar satu design utk carousel: gambar laman web (kikisan, cache sehari) dahulu, kemudian gambar cermin jika berbeza/satu-satunya.
     * Boleh lambat kali pertama (kikisan) - sebab itu dihantar DITANGGUH. `reference` = gambar cermin yg sekadar rujukan (berat terdekat).
     *
     * @return array{urls: list<string>, reference: bool}
     */
    public static function designImages(string $code): array
    {
        $code = trim($code);
        $urls = array_values(array_unique(ProductImageFetcher::imageUrlsFor($code)));
        $mirror = ProductImageFetcher::mirrorImageFor($code);

        if ($mirror !== null && ! in_array($mirror, $urls, true)) {
            $urls[] = $mirror;
        }

        $reference = $mirror !== null && ProductImageFetcher::isReferenceImage(ProductImageFetcher::referenceImages([$code]), $code, $mirror);

        return ['urls' => $urls, 'reference' => $reference];
    }

    /**
     * Cadangan autocomplete: supplier mengikut KOD (awalan) atau NAMA (mengandungi). Jadual vendor kecil, jadi sentiasa pantas &
     * tidak bergantung pd agregat tempoh. Kod '.'/kosong ialah vendor palsu - tak ditawarkan.
     *
     * @return array{suppliers: list<array{code: string, name: string}>}
     */
    public static function suggestions(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['suppliers' => []];
        }

        // Aksara LIKE (% _ \) dilepaskan - input pengguna dipadankan secara harfiah.
        $like = addcslashes($term, '%_\\');

        $suppliers = Vendor::query()
            ->whereRaw("TRIM(VendorCode) NOT IN ('.', '')")
            ->where(fn ($query) => $query->where('VendorCode', 'like', $like.'%')->orWhere('Description', 'like', '%'.$like.'%'))
            // Padanan kod dahulu, kemudian nama.
            ->orderByRaw('CASE WHEN VendorCode LIKE ? THEN 0 ELSE 1 END', [$like.'%'])
            ->orderBy('Description')
            ->limit(self::SUGGESTION_LIMIT)
            ->get(['VendorCode', 'Description'])
            ->map(fn (Vendor $vendor): array => [
                'code' => trim((string) $vendor->VendorCode),
                'name' => trim((string) $vendor->Description) !== '' ? trim((string) $vendor->Description) : trim((string) $vendor->VendorCode),
            ])
            ->all();

        return ['suppliers' => $suppliers];
    }

    /** Nama supplier utk satu kod (paparan medan terpilih), atau null jika tiada. */
    public static function supplierName(string $code): ?string
    {
        $name = Vendor::query()->where('VendorCode', $code)->value('Description');

        return filled($name) ? trim((string) $name) : null;
    }

    /** Panaskan cache agregat tempoh ini (rujuk perintah `supplier-lookup:warm`); pulangkan bilangan baris design x cawangan x supplier. */
    public function warm(): int
    {
        self::categoryByDesign();

        return $this->ensurePartitions() + $this->ensureSalesPartitions();
    }

    /**
     * Agregat (design x cawangan) SATU supplier dlm tempoh. Seluruh tempoh dikira SEKALI (semua supplier) lalu dipecah &
     * di-cache ikut supplier - carian hanya membaca bahagian supplier itu (kecil), bukan ~78K baris semua supplier.
     *
     * @return list<array{code: string, store: string, vendor: string, buy: int, sold: int}>
     */
    private function groups(string $supplier): array
    {
        $this->ensurePartitions();

        return Cache::get($this->cachePrefix().':vendor:'.$supplier, []);
    }

    /**
     * Jualan (Status 0/9, SalesDate dlm tempoh JUALAN) bagi SATU supplier ikut design x cawangan - dicache berasingan drpd stock in supaya
     * dua tempoh boleh digabung bebas tanpa mengira semula yg lain.
     *
     * @return list<array{code: string, store: string, vendor: string, sold: int}>
     */
    private function salesGroups(string $supplier): array
    {
        $this->ensureSalesPartitions();

        return Cache::get($this->salesCachePrefix().':vendor:'.$supplier, []);
    }

    private function salesCachePrefix(): string
    {
        return sprintf(
            'supplier-lookup-sales:v1:%s:%s:%s',
            $this->salesFrom->toDateString(),
            $this->salesTo->toDateString(),
            md5((string) InventoryPiece::query()->max('synced_at')),
        );
    }

    /** Sama spt ensurePartitions() utk agregat jualan. */
    private function ensureSalesPartitions(): int
    {
        $prefix = $this->salesCachePrefix();

        return Cache::remember($prefix.':ready', now()->addMinutes(self::CACHE_MINUTES), function () use ($prefix): int {
            $groups = $this->aggregateSales();

            foreach (collect($groups)->groupBy('vendor') as $vendor => $rows) {
                Cache::put($prefix.':vendor:'.$vendor, $rows->values()->all(), now()->addMinutes(self::CACHE_MINUTES));
            }

            return count($groups);
        });
    }

    /**
     * @return list<array{code: string, store: string, vendor: string, sold: int}>
     */
    private function aggregateSales(): array
    {
        return InventoryPiece::query()
            ->when($this->hasIndex(self::SALES_INDEX), fn ($query) => $query->useIndex(self::SALES_INDEX))
            ->realVendor()
            ->whereNotNull('InternalCode')
            ->where('InternalCode', '!=', '')
            ->whereIn('Status', self::SOLD_STATUSES)
            ->whereBetween('SalesDate', [$this->salesFrom->toDateTimeString(), $this->salesTo->toDateTimeString()])
            ->selectRaw('InternalCode, StoreCode, TRIM(VendorCode) as vendor, COUNT(*) as sold')
            ->groupBy('InternalCode', 'StoreCode', 'vendor')
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'code' => trim((string) $row->InternalCode),
                'store' => trim((string) $row->StoreCode),
                'vendor' => (string) $row->vendor,
                'sold' => (int) $row->sold,
            ])
            ->all();
    }

    /**
     * Kunci cache mengandungi tempoh & masa segerak cermin terakhir - cache lama tak dipakai selepas segerak berikutnya.
     */
    private function cachePrefix(): string
    {
        return sprintf(
            'supplier-lookup:v6:%s:%s:%s',
            $this->from->toDateString(),
            $this->to->toDateString(),
            md5((string) InventoryPiece::query()->max('synced_at')),
        );
    }

    /** Kira agregat penuh sekali & simpan bahagian setiap supplier (penanda `ready` ditulis terakhir). */
    private function ensurePartitions(): int
    {
        $prefix = $this->cachePrefix();

        return Cache::remember($prefix.':ready', now()->addMinutes(self::CACHE_MINUTES), function () use ($prefix): int {
            $groups = $this->aggregate();

            foreach (collect($groups)->groupBy('vendor') as $vendor => $rows) {
                Cache::put($prefix.':vendor:'.$vendor, $rows->values()->all(), now()->addMinutes(self::CACHE_MINUTES));
            }

            return count($groups);
        });
    }

    /**
     * @return list<array{code: string, store: string, vendor: string, buy: int, sold: int}>
     */
    private function aggregate(): array
    {
        $soldList = "'".implode("','", self::SOLD_STATUSES)."'";

        // Hanya lajur dlm index covering: query dijawab drpd index sahaja (diukur ~0.3 saat utk 30 hari).
        return InventoryPiece::query()
            ->when($this->hasIndex(self::COVERING_INDEX), fn ($query) => $query->useIndex(self::COVERING_INDEX))
            ->realVendor()
            ->whereNotNull('InternalCode')
            ->where('InternalCode', '!=', '')
            ->whereBetween('PurchDate', [$this->from->toDateTimeString(), $this->to->toDateTimeString()])
            ->selectRaw(
                'InternalCode, StoreCode, TRIM(VendorCode) as vendor, COUNT(*) as buy, '
                ."SUM(CASE WHEN Status IN ({$soldList}) THEN 1 ELSE 0 END) as sold",
            )
            ->groupBy('InternalCode', 'StoreCode', 'vendor')
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'code' => trim((string) $row->InternalCode),
                'store' => trim((string) $row->StoreCode),
                'vendor' => (string) $row->vendor,
                'buy' => (int) $row->buy,
                'sold' => (int) $row->sold,
            ])
            ->all();
    }

    /**
     * ` FORCE INDEX (...)` utk satu index covering jika wujud (rujuk migrasi board covering indexes), selain itu kosong - query tetap
     * betul tanpa petunjuk. Kewujudan index di-cache sejam.
     */
    private static function indexHint(string $index): string
    {
        $exists = Cache::remember('supplier-lookup:index:'.$index, now()->addHour(), function () use ($index): bool {
            try {
                return Schema::hasIndex('jemisys_inventory_mirror', $index);
            } catch (\Throwable) {
                return false;
            }
        });

        return $exists ? " FORCE INDEX ({$index})" : '';
    }

    /** Index covering mungkin belum wujud (migrasi belum dijalankan) - tanpa petunjuk index, query tetap betul tetapi perlahan. */
    private function hasIndex(string $index): bool
    {
        return Cache::remember('supplier-lookup:has-index:'.$index, now()->addHour(), function () use ($index): bool {
            try {
                return Schema::hasIndex('jemisys_inventory_mirror', $index);
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
