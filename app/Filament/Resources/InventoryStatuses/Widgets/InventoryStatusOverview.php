<?php

namespace App\Filament\Resources\InventoryStatuses\Widgets;

use App\Enums\JemisysInventoryStatus;
use App\Filament\Resources\InventoryStatuses\Pages\ListInventoryStatuses;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;

use function Livewire\trigger;

/**
 * Ikut data table SEBENAR (search/Query Builder filter semasa) - REKA BENTUK sama spt
 * Filament\Widgets\Concerns\InteractsWithPageTable (mekanisme rasmi utk widget "ikut" jadual pd
 * halaman yg sama: tableSearch/tableFilters dikongsi sbg #[Reactive] props, widget auto render
 * semula bila user tukar search/filter, tanpa wiring event manual) - TAPI disalin terus (bukan
 * `use InteractsWithPageTable;`) sebab trait asal declare `public array $tableColumnSearches`
 * (non-nullable), dan Livewire hydrate reactive prop ni dgn `null` pd render pertama widget
 * (bug/quirk versi semasa) punca fatal "Cannot assign null to property ... of type array". PHP
 * tak benarkan class override jenis property trait secara senyap (mesti sama PERSIS atau fatal
 * "definition differs"), jadi satu-satunya jalan selamat ialah salin logik trait terus dgn
 * jenis `?array` utk lajur ni sahaja. Jadual InventoryStatusesTable tiada lajur
 * individually-searchable pun (cuma global search box), jadi tak hilang apa-apa keupayaan
 * sebenar.
 *
 * Cache::remember (BUKAN rememberForever) dgn key ikut SQL+binding query semasa (bukan satu
 * key tetap) - setiap kombinasi search/filter dapat cache SENDIRI, so reactivity kekal betul,
 * TAPI kombinasi yg SAMA diulang (cth. buka page pertama kali tanpa filter, keadaan paling
 * kerap) tak perlu kira semula. Ni disahkan PENTING production - laku/takLaku (groupBy
 * InternalCode + havingRaw, ~481K baris realVendor() padan) kadangkala ambil 15-40s bergantung
 * beban InnoDB buffer pool/temp table semasa (disahkan berulang kali - query SAMA, plan SAMA,
 * masa berbeza jauh ikut keadaan server), cukup lama utk nampak "page tak boleh buka" kat
 * production (gateway/proxy timeout) walhal sebenarnya cuma perlahan, bukan rosak. Cache raw
 * ARRAY sahaja (bukan Stat objek - Stat/Collection di-cache boleh pulang
 * __PHP_Incomplete_Class_Name bila unserialize, isu class-loading biasa) - Stat dibina semula
 * setiap render drpd data cache, murah (tiada query).
 *
 * TIADA HasWidgetShield - widget page-level (getHeaderWidgets() pd ListInventoryStatuses),
 * bukan didaftar dlm AdminPanelProvider->widgets() dashboard, jadi FilamentShield::getWidgets()
 * tak kenal dia (getWidgetPermission() pulang null) - fallback HasWidgetShield ke
 * parent::canAccess() akan crash sbb Filament\Widgets\Widget tiada method tsb. Sama sebab
 * ActiveStockTransfersTable/ActiveStockRearrangementStopsTable (footer widget lain) turut tiada
 * HasWidgetShield - rujuk komen AdminPanelProvider.
 */
class InventoryStatusOverview extends StatsOverviewWidget
{
    private const CACHE_TTL_SECONDS = 300;

    /** @var array<string, int> */
    #[Reactive]
    public $paginators = [];

    #[Reactive]
    public ?int $tableRecordsCount = null;

    /** @var array<string, string | array<string, string | null> | null> */
    #[Reactive]
    public ?array $tableColumnSearches = [];

    #[Reactive]
    public ?string $tableGrouping = null;

    /** @var array<string, mixed> | null */
    #[Reactive]
    public ?array $tableFilters = null;

    #[Reactive]
    public int|string|null $tableRecordsPerPage = null;

    #[Reactive]
    public ?string $tableSearch = '';

    #[Reactive]
    public ?string $tableSort = null;

    #[Reactive]
    public ?string $activeTab = null;

    #[Reactive]
    #[Locked]
    public ?Model $parentRecord = null;

    protected HasTable $tablePage;

    protected ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListInventoryStatuses::class;
    }

    protected function getTablePageInstance(): HasTable
    {
        if (isset($this->tablePage)) {
            return $this->tablePage;
        }

        /** @var HasTable $page */
        $page = app('livewire')->new($this->getTablePage());

        trigger('mount', $page, [], null, null, []);

        foreach ([
            'activeTab' => $this->activeTab,
            'paginators' => $this->paginators,
            'parentRecord' => $this->parentRecord,
            'tableColumnSearches' => $this->tableColumnSearches ?? [],
            'tableFilters' => $this->tableFilters,
            'tableGrouping' => $this->tableGrouping,
            'tableRecordsPerPage' => $this->tableRecordsPerPage,
            'tableSearch' => $this->tableSearch,
            'tableSort' => $this->tableSort,
        ] as $property => $value) {
            $page->{$property} = $value;
        }

        $page->bootedInteractsWithTable();

        return $this->tablePage = $page;
    }

    protected function getPageTableQuery(): Builder
    {
        return $this->getTablePageInstance()->getFilteredSortedTableQuery();
    }

    protected function getPageTableRecords(): Collection|Paginator
    {
        return $this->getTablePageInstance()->getTableRecords();
    }

    /** @return array{total:int, by_status:array<string,int>, by_store:array<string,int>, laku:int, tak_laku:int} */
    protected function computeData(): array
    {
        $query = $this->getPageTableQuery();

        $cacheKey = 'inventory_status_overview_'.md5($query->toSql().serialize($query->getBindings()));

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($query) {
            // SENGAJA 4 query berasingan (bukan digabung) - byStatus/byStore masing2 guna index
            // SATU lajur sendiri (idx_mirror_status/StoreCode index) bila TIADA search aktif
            // (~0.6s setiap satu). Pernah cuba gabung jadi 1 GROUP BY(Status,StoreCode) utk
            // kurangkan bil. scan bila carian teks bebas aktif (LIKE merentas 3 lajur tak leh
            // guna index langsung, jadi setiap scan tambahan mahal) - TAPI itu paksa MySQL guna
            // temp table/sort utk groupBy 2 lajur, regresi kes biasa (tiada carian) drpd ~1s ke
            // ~39s. Kes biasa (tiada carian) jauh lebih kerap drpd kes carian teks bebas 502K
            // baris, jadi kekal berasingan - carian teks bebas yg perlahan (~20-60s/query) ialah
            // had tersirat SQL LIKE tanpa index sesuai, bukan sesuatu yg boleh dibetulkan di
            // lapisan widget ni (rujuk Cache::remember di atas - itulah mitigasinya).
            $byStatus = (clone $query)->toBase()->reorder()
                ->selectRaw('Status, COUNT(*) as cnt')
                ->groupBy('Status')
                ->pluck('cnt', 'Status');

            $byStore = (clone $query)->toBase()->reorder()
                ->selectRaw('StoreCode, COUNT(*) as cnt')
                ->groupBy('StoreCode')
                ->orderBy('StoreCode')
                ->pluck('cnt', 'StoreCode');

            $soldExpr = 'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END)';

            $laku = (clone $query)->realVendor()->toBase()->reorder()
                ->selectRaw("InternalCode, {$soldExpr} as sold, SUM(QtyOnHand) as stock")
                ->groupBy('InternalCode')
                ->havingRaw("{$soldExpr} >= 3 AND SUM(QtyOnHand) = 0")
                ->get()
                ->count();

            $takLaku = (clone $query)->realVendor()->toBase()->reorder()
                ->selectRaw("InternalCode, {$soldExpr} as sold, SUM(QtyOnHand) as stock")
                ->groupBy('InternalCode')
                ->havingRaw("{$soldExpr} = 0 AND SUM(QtyOnHand) > 0")
                ->get()
                ->count();

            return [
                'total' => (int) $byStatus->sum(),
                // toArray() - Collection tersimpan sbg objek dlm cache boleh pulang
                // __PHP_Incomplete_Class_Name bila unserialize semula.
                'by_status' => $byStatus->toArray(),
                'by_store' => $byStore->toArray(),
                'laku' => $laku,
                'tak_laku' => $takLaku,
            ];
        });
    }

    protected function getStats(): array
    {
        $data = $this->computeData();

        $stats = [
            Stat::make('Jumlah Keseluruhan', number_format($data['total']))
                ->description('Ikut carian/filter semasa')
                ->color('primary'),
        ];

        foreach ($data['by_status'] as $code => $count) {
            $stats[] = Stat::make('Status: '.JemisysInventoryStatus::labelFor($code), number_format((int) $count))
                ->color(JemisysInventoryStatus::colorFor($code));
        }

        foreach ($data['by_store'] as $store => $count) {
            $stats[] = Stat::make('Cawangan: '.$store, number_format((int) $count))
                ->color('gray');
        }

        $stats[] = Stat::make('Design Laku', number_format($data['laku']))
            ->description('>=3 terjual & stok=0 (proven seller, sold out)')
            ->color($data['laku'] > 0 ? 'danger' : 'success');

        $stats[] = Stat::make('Design Tak Laku', number_format($data['tak_laku']))
            ->description('Ada stok, tak pernah terjual langsung')
            ->color('warning');

        return $stats;
    }
}
