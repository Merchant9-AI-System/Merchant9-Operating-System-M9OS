<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\RestockBucketSnapshot;
use App\Models\RestockDailyCount;
use App\Models\RestockSnapshot;
use App\Models\RestockVerdictChange;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Kira ringkasan restock sekali (dipanggil dari SyncJemisysMirrors selepas InventoryPiece disegerak, atau manual melalui
 * `app:build-restock-snapshot`) & simpan ke restock_snapshots (design x cawangan) dan restock_bucket_snapshots (kategori x cawangan x
 * saiz/berat). Halaman Restock Analysis membaca jadual-jadual ini terus - BUKAN agregat ~490K piece setiap permintaan.
 *
 * Jualan/penerimaan dikira utk SEMUA tempoh (RestockSnapshot::PERIODS) dlm SATU pas pertanyaan, jadi menukar tempoh di UI tidak perlu
 * pengiraan semula. Cadangan/Disyorkan/Gap sentiasa dikira drpd tetingkap TREND_MONTHS (3 bulan) tetap, dgn sampel minimum diukur
 * drpd SEMUA penerimaan - peraturan sama spt RestockAnalysisCalculator (verdictFor()).
 *
 * Jadual KEKAL berisi semasa pengiraan: baris di-upsert (bukan truncate) kemudian baris lapuk (tidak disentuh larian ini) dibuang, jadi
 * pengguna yg membuka halaman semasa segerak malam tidak nampak jadual kosong.
 *
 * Perubahan Cadangan berbanding snapshot sebelumnya direkod di restock_verdict_changes (sejarah ringkas, bukan snapshot harian penuh).
 * Rekod baharu tanpa snapshot sebelumnya (larian pertama) TIDAK direkod supaya tiada banjir "perubahan" palsu.
 *
 * cursor() + buffer (bukan get() penuh) - corak sama spt StockoutReorderMaterializer: memori kekal ~saiz buffer walaupun ~130K baris.
 */
class RestockSnapshotMaterializer
{
    private const CHUNK_SIZE = 500;

    /** Sejarah perubahan Cadangan disimpan berapa hari. */
    public const CHANGE_RETENTION_DAYS = 90;

    /** Kiraan harian (carta trend) disimpan berapa hari. */
    public const DAILY_COUNT_RETENTION_DAYS = 400;

    /**
     * @return array{designs: int, buckets: int, changes: int}
     */
    public static function materialize(): array
    {
        $runAt = now()->startOfSecond();
        $velocityDays = max((int) now()->subMonths(RestockAnalysisCalculator::TREND_MONTHS)->diffInDays(now()), 1);

        $designs = static::materializeDesigns($runAt, $velocityDays);
        $buckets = static::materializeBuckets($runAt, $velocityDays);
        static::recordDailyCounts($runAt);

        RestockVerdictChange::query()->where('changed_at', '<', now()->subDays(self::CHANGE_RETENTION_DAYS))->delete();
        RestockDailyCount::query()->where('counted_on', '<', now()->subDays(self::DAILY_COUNT_RETENTION_DAYS)->toDateString())->delete();

        return ['designs' => $designs['rows'], 'buckets' => $buckets, 'changes' => $designs['changes']];
    }

    /**
     * @return array{rows: int, changes: int}
     */
    private static function materializeDesigns(CarbonInterface $runAt, int $velocityDays): array
    {
        [$sums, $bindings] = static::windowSums();

        $previous = [];
        foreach (RestockSnapshot::query()->toBase()->select(['internal_code', 'store_code', 'verdict'])->cursor() as $row) {
            $previous[$row->internal_code.'|'.$row->store_code] = $row->verdict;
        }

        $hasPrevious = $previous !== [];
        $buffer = [];
        $changes = [];
        $rows = 0;
        $changeCount = 0;

        $query = InventoryPiece::query()
            ->realVendor()
            ->whereNotNull('InternalCode')
            ->selectRaw(
                'TRIM(InternalCode) as internal_code, TRIM(StoreCode) as store_code, MAX(CategoryCode) as category_code, '.
                'MAX(VendorCode) as vendor_code, MAX(Description) as description, SUM(QtyOnHand) as stock, MAX(SalesDate) as last_sold_at, '.$sums,
                $bindings,
            )
            ->groupByRaw('TRIM(InternalCode), TRIM(StoreCode)')
            ->toBase();

        foreach ($query->cursor() as $r) {
            if ($r->internal_code === null || $r->internal_code === '') {
                continue;
            }

            $metrics = static::metrics($r, $velocityDays);
            $categoryCode = trim((string) $r->category_code);

            $buffer[] = [
                'internal_code' => (string) $r->internal_code,
                'store_code' => (string) $r->store_code,
                'category_code' => $categoryCode,
                'vendor_code' => trim((string) $r->vendor_code) ?: null,
                'description' => Str::limit(trim((string) $r->description), 255, ''),
                'last_sold_at' => $r->last_sold_at,
                'computed_at' => $runAt,
            ] + $metrics;

            $old = $previous[$r->internal_code.'|'.$r->store_code] ?? null;
            $new = $metrics['verdict'];

            if ($hasPrevious && $old !== $new && ($old !== null || in_array($new, [RestockAnalysisCalculator::VERDICT_SOLD_OUT, RestockAnalysisCalculator::VERDICT_RESTOCK], true))) {
                $changes[] = [
                    'internal_code' => (string) $r->internal_code,
                    'store_code' => (string) $r->store_code,
                    'category_code' => $categoryCode,
                    'from_verdict' => $old,
                    'to_verdict' => $new,
                    'changed_at' => $runAt,
                ];
            }

            if (count($buffer) >= self::CHUNK_SIZE) {
                static::flushDesigns($buffer);
                $rows += count($buffer);
                $buffer = [];
            }

            if (count($changes) >= self::CHUNK_SIZE) {
                RestockVerdictChange::insert($changes);
                $changeCount += count($changes);
                $changes = [];
            }
        }

        if ($buffer !== []) {
            static::flushDesigns($buffer);
            $rows += count($buffer);
        }

        if ($changes !== []) {
            RestockVerdictChange::insert($changes);
            $changeCount += count($changes);
        }

        // Baris yg tidak disentuh larian ini = design x cawangan yg sudah tiada lagi dlm mirror.
        RestockSnapshot::query()->where('computed_at', '<', $runAt)->delete();

        return ['rows' => $rows, 'changes' => $changeCount];
    }

    /**
     * Rekod kiraan Sold Out / Perlu Restock setiap cawangan utk hari ini (larian kedua pada hari sama menimpa - satu baris sehari).
     */
    private static function recordDailyCounts(CarbonInterface $runAt): void
    {
        $rows = RestockSnapshot::query()
            ->selectRaw('store_code, SUM(CASE WHEN verdict = ? THEN 1 ELSE 0 END) as sold_out, SUM(CASE WHEN verdict = ? THEN 1 ELSE 0 END) as restock', [
                RestockAnalysisCalculator::VERDICT_SOLD_OUT,
                RestockAnalysisCalculator::VERDICT_RESTOCK,
            ])
            ->groupBy('store_code')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'counted_on' => $runAt->toDateString(),
                'store_code' => $row->store_code,
                'sold_out' => (int) $row->sold_out,
                'restock' => (int) $row->restock,
            ])
            ->all();

        if ($rows !== []) {
            RestockDailyCount::upsert($rows, ['counted_on', 'store_code'], ['sold_out', 'restock']);
        }
    }

    /** @param  list<array<string, mixed>>  $buffer */
    private static function flushDesigns(array $buffer): void
    {
        RestockSnapshot::upsert($buffer, ['internal_code', 'store_code'], array_keys($buffer[0]));
    }

    private static function materializeBuckets(CarbonInterface $runAt, int $velocityDays): int
    {
        $total = 0;

        foreach ([RestockBucketSnapshot::KIND_SIZE, RestockBucketSnapshot::KIND_WEIGHT] as $kind) {
            $buffer = [];

            foreach (static::aggregateBuckets($kind) as $bucket) {
                $buffer[] = [
                    'kind' => $kind,
                    'category_code' => $bucket['category_code'],
                    'store_code' => $bucket['store_code'],
                    'bucket' => $bucket['bucket'],
                    'computed_at' => $runAt,
                ] + static::metrics((object) $bucket['counts'], $velocityDays);

                if (count($buffer) >= self::CHUNK_SIZE) {
                    static::flushBuckets($buffer);
                    $total += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                static::flushBuckets($buffer);
                $total += count($buffer);
            }
        }

        RestockBucketSnapshot::query()->where('computed_at', '<', $runAt)->delete();

        return $total;
    }

    /** @param  list<array<string, mixed>>  $buffer */
    private static function flushBuckets(array $buffer): void
    {
        RestockBucketSnapshot::upsert($buffer, ['kind', 'category_code', 'store_code', 'bucket'], array_keys($buffer[0]));
    }

    /**
     * Agregat bucket: saiz dikumpul drpd JewelSize mentah kemudian digabung ikut label (sizeLabel()), berat dikumpul terus dlm SQL
     * (CASE bucket) - sama spt RestockAnalysisCalculator::computeBySize()/computeByWeight().
     *
     * @return list<array{category_code: string, store_code: string, bucket: string, counts: array<string, int|string|null>}>
     */
    private static function aggregateBuckets(string $kind): array
    {
        [$sums, $bindings] = static::windowSums();
        $isSize = $kind === RestockBucketSnapshot::KIND_SIZE;
        $bucketSql = $isSize ? 'JewelSize' : RestockAnalysisCalculator::weightBucketSqlCase();

        $raw = InventoryPiece::query()
            ->realVendor()
            ->selectRaw("TRIM(CategoryCode) as category_code, TRIM(StoreCode) as store_code, {$bucketSql} as raw_bucket, SUM(QtyOnHand) as stock, {$sums}", $bindings)
            ->groupByRaw("TRIM(CategoryCode), TRIM(StoreCode), {$bucketSql}")
            ->toBase()
            ->get();

        $merged = [];

        foreach ($raw as $r) {
            $bucket = $isSize ? RestockAnalysisCalculator::sizeLabel($r->raw_bucket) : (string) $r->raw_bucket;
            $key = $r->category_code.'|'.$r->store_code.'|'.$bucket;

            if (! isset($merged[$key])) {
                $merged[$key] = ['category_code' => (string) $r->category_code, 'store_code' => (string) $r->store_code, 'bucket' => $bucket, 'counts' => ['stock' => 0]];
            }

            $merged[$key]['counts']['stock'] += (int) $r->stock;

            foreach (static::countColumns() as $column) {
                $merged[$key]['counts'][$column] = ($merged[$key]['counts'][$column] ?? 0) + (int) $r->{$column};
            }
        }

        return array_values($merged);
    }

    /**
     * Medan kiraan + Cadangan utk satu baris agregat (design atau bucket).
     *
     * @return array<string, int|float|string|null>
     */
    private static function metrics(object $r, int $velocityDays): array
    {
        $stock = (int) $r->stock;
        $velocity = SalesVelocityHelper::velocity((int) $r->sold_3m, $velocityDays);
        $target = SalesVelocityHelper::targetStock($velocity, RestockAnalysisCalculator::TARGET_COVER_MONTHS);

        $metrics = ['stock' => $stock];

        foreach (static::countColumns() as $column) {
            $metrics[$column] = (int) $r->{$column};
        }

        $verdict = RestockAnalysisCalculator::verdictFor((int) $r->received_all, $stock, $velocity, $target);

        return $metrics + [
            'velocity_per_month' => $velocity,
            'target_stock' => $target,
            'gap' => $target - $stock,
            'cover_months' => $velocity > 0 ? round($stock / $velocity, 1) : null,
            'verdict' => $verdict,
            'urgency' => RestockSnapshotQuery::urgencyOf($verdict),
        ];
    }

    /** @return list<string> sold_{tempoh} & received_{tempoh} */
    private static function countColumns(): array
    {
        $columns = [];

        foreach (['sold', 'received'] as $prefix) {
            foreach (RestockSnapshot::PERIODS as $period) {
                $columns[] = "{$prefix}_{$period}";
            }
        }

        return $columns;
    }

    /**
     * Ungkapan SUM(CASE ...) utk setiap tempoh (jualan ikut SalesDate, penerimaan ikut PurchDate) + nilai binding mengikut tertib.
     *
     * @return array{0: string, 1: list<CarbonInterface>}
     */
    private static function windowSums(): array
    {
        $parts = [];
        $bindings = [];

        foreach (['sold' => 'SalesDate', 'received' => 'PurchDate'] as $prefix => $dateColumn) {
            foreach (RestockSnapshot::PERIODS as $period) {
                $parts[] = "SUM(CASE WHEN {$dateColumn} >= ? THEN 1 ELSE 0 END) as {$prefix}_{$period}";
                $bindings[] = RestockAnalysisCalculator::trendStartForPeriod($period);
            }
        }

        return [implode(', ', $parts), $bindings];
    }
}
