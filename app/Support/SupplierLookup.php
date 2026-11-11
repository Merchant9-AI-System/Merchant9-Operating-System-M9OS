<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /** Paling banyak cadangan supplier dlm autocomplete. */
    public const SUGGESTION_LIMIT = 8;

    /** Paling banyak kod design bagi satu permintaan details() (satu halaman jadual = 25). */
    public const MAX_DETAIL_CODES = 60;

    /** Cermin hanya berubah bila disegerak (harian), & kunci cache mengandungi masa segerak - jadi TTL panjang selamat. */
    private const CACHE_MINUTES = 1440;

    /** Index covering (PurchDate, InternalCode, StoreCode, Status, VendorCode) - rujuk migrasi dgn nama yg sama. */
    private const COVERING_INDEX = 'idx_mirror_supplier_lookup_covering';

    public function __construct(private readonly Carbon $from, private readonly Carbon $to) {}

    public static function forDays(int $days): self
    {
        $to = now()->endOfDay();

        return new self($to->copy()->subDays($days - 1)->startOfDay(), $to);
    }

    public static function normalizeDays(mixed $days): int
    {
        return in_array((int) $days, self::PERIOD_DAYS, true) ? (int) $days : self::DEFAULT_DAYS;
    }

    /**
     * Semua design SATU supplier dlm tempoh: kod + setiap cawangan => [Amik, Sold]. Disusun ikut kod (klien yg menyusun semula).
     *
     * @return list<array{code: string, stores: array<string, array{0: int, 1: int}>}>
     */
    public function designs(string $supplier): array
    {
        $designs = [];

        foreach ($this->groups($supplier) as $group) {
            $designs[$group['code']]['code'] = $group['code'];

            // Kod mentah berbeza selepas trim (cth. aksara TAB) boleh jatuh pd design & cawangan yg sama - dijumlahkan.
            $buy = ($designs[$group['code']]['stores'][$group['store']][0] ?? 0) + $group['buy'];
            $sold = ($designs[$group['code']]['stores'][$group['store']][1] ?? 0) + $group['sold'];
            $designs[$group['code']]['stores'][$group['store']] = [$buy, $sold];
        }

        ksort($designs, SORT_STRING);

        // Cawangan juga disusun (kekunci) - susunan tetap, tidak bergantung pd susunan baris DB.
        return array_values(array_map(function (array $design): array {
            ksort($design['stores'], SORT_STRING);

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
        $codes = collect($codes)
            ->filter(fn ($code) => is_string($code))
            ->map(fn (string $code) => mb_substr(trim($code), 0, 20))
            ->filter()
            ->unique()
            ->take(self::MAX_DETAIL_CODES)
            ->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $rows = InventoryPiece::query()
            ->whereIn('InternalCode', $codes->all())
            ->selectRaw('InternalCode, MAX(nickname) as nickname, MAX(image_url) as image_url, MAX(image_source) as image_source')
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => trim((string) $row->InternalCode));

        return $codes->mapWithKeys(function (string $code) use ($rows): array {
            $row = $rows->get($code);
            $hasImage = $row !== null && filled($row->image_url);

            return [$code => [
                'nickname' => $row !== null && filled($row->nickname) ? trim((string) $row->nickname) : null,
                'image_url' => $hasImage ? (string) $row->image_url : null,
                'image_reference' => $hasImage && $row->image_source === DesignFamily::SOURCE_NEAREST,
            ]];
        })->all();
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
        return $this->ensurePartitions();
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
            ->when($this->hasCoveringIndex(), fn ($query) => $query->useIndex(self::COVERING_INDEX))
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

    /** Index covering mungkin belum wujud (migrasi belum dijalankan) - tanpa petunjuk index, query tetap betul tetapi perlahan. */
    private function hasCoveringIndex(): bool
    {
        return Cache::remember('supplier-lookup:covering-index', now()->addHour(), function (): bool {
            try {
                return Schema::hasIndex('jemisys_inventory_mirror', self::COVERING_INDEX);
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
