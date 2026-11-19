<?php

namespace App\Support;

use App\Models\RestockBucketSnapshot;
use App\Models\RestockSnapshot;
use App\Models\RestockVerdictChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pertanyaan halaman Restock Analysis ke atas restock_snapshots (design x cawangan) & restock_bucket_snapshots (saiz/berat):
 * penapis, susunan berbilang (senarai putih lajur) dan ringkasan - semuanya di database, jadi halaman tidak memuat ribuan baris.
 *
 * Penapis ( @phpstan-type Filters ):
 *   category list<string>, store list<string>, verdict list<string>, q string (kod design/penerangan atau bucket), new bool (hanya yg
 *   Cadangannya baharu bertukar pada snapshot terkini - rujuk RestockVerdictChange).
 *
 * @phpstan-type Filters array{category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool}
 */
class RestockSnapshotQuery
{
    /** Susunan lalai: Sold Out dahulu, kemudian kekurangan (Gap) terbesar. */
    public const DEFAULT_SORT = 'urgency';

    public const MAX_SORTS = 4;

    /** Lajur boleh disusun utk senarai design. */
    public const DESIGN_SORTS = ['urgency', 'gap', 'stock', 'sold_1w', 'sold_1m', 'sold_3m', 'cover_months', 'last_sold_at', 'internal_code', 'category', 'store', 'verdict'];

    /** Lajur boleh disusun utk bucket saiz/berat. */
    public const BUCKET_SORTS = ['urgency', 'gap', 'stock', 'sold_1w', 'sold_1m', 'sold_3m', 'cover_months', 'bucket', 'category', 'store', 'verdict'];

    /** @var list<string> Cadangan dari paling mendesak (indeks = kedudukan `urgency`). */
    public const VERDICT_ORDER = [
        RestockAnalysisCalculator::VERDICT_SOLD_OUT,
        RestockAnalysisCalculator::VERDICT_RESTOCK,
        RestockAnalysisCalculator::VERDICT_OVERSTOCK,
        RestockAnalysisCalculator::VERDICT_OK,
        RestockAnalysisCalculator::VERDICT_NO_DATA,
    ];

    /**
     * @param  array{category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool}  $filters
     * @return Builder<RestockSnapshot>
     */
    public static function designs(array $filters, bool $withVerdict = true): Builder
    {
        $query = RestockSnapshot::query();

        static::applyCommon($query, $filters, $withVerdict);

        if ($filters['q'] !== '') {
            $like = '%'.static::escapeLike($filters['q']).'%';
            $query->where(fn (Builder $q) => $q->where('internal_code', 'like', $like)->orWhere('description', 'like', $like));
        }

        if ($filters['new'] && $withVerdict) {
            $latest = static::latestChangeAt();

            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('restock_verdict_changes as c')
                ->whereColumn('c.internal_code', 'restock_snapshots.internal_code')
                ->whereColumn('c.store_code', 'restock_snapshots.store_code')
                ->whereColumn('c.to_verdict', 'restock_snapshots.verdict')
                ->where('c.changed_at', $latest ?? '1970-01-01'));
        }

        return $query;
    }

    /**
     * @param  array{category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool}  $filters
     * @return Builder<RestockBucketSnapshot>
     */
    public static function buckets(string $kind, array $filters, bool $withVerdict = true): Builder
    {
        $query = RestockBucketSnapshot::query()->where('kind', $kind);

        static::applyCommon($query, $filters, $withVerdict);

        if ($filters['q'] !== '') {
            $like = '%'.static::escapeLike($filters['q']).'%';
            $query->where(fn (Builder $q) => $q->where('bucket', 'like', $like)->orWhere('category_code', 'like', $like));
        }

        return $query;
    }

    /**
     * Susunan berbilang: kunci mesti dlm senarai putih; pemutus seri terakhir ialah id (susunan stabil antara halaman).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<array{key: string, dir: string}>  $sorts
     * @return Builder<TModel>
     */
    public static function applySort(Builder $query, array $sorts, string $scope): Builder
    {
        foreach ($sorts as $sort) {
            $dir = $sort['dir'] === 'asc' ? 'asc' : 'desc';

            match ($sort['key']) {
                // asc = paling mendesak dahulu (Sold Out, kemudian Gap terbesar); desc = songsang.
                'urgency' => $query->orderBy('urgency', $dir)->orderBy('gap', $dir === 'asc' ? 'desc' : 'asc'),
                'verdict' => $query->orderBy('urgency', $dir),
                // Tiada jualan (cover null) dianggap "cukup lama" - hujung bila menaik.
                'cover_months' => $query->orderByRaw('COALESCE(cover_months, 99999) '.$dir),
                'category' => $query->orderBy('category_code', $dir),
                'store' => $query->orderBy('store_code', $dir),
                default => $query->orderBy($sort['key'], $dir),
            };
        }

        return $query->orderBy('id');
    }

    /**
     * Tafsir parameter susunan "kunci:arah,kunci:arah" kepada senarai tervalidasi (maks. MAX_SORTS, tanpa pendua).
     *
     * @param  list<string>  $allowed
     * @return list<array{key: string, dir: string}>
     */
    public static function parseSorts(?string $value, array $allowed): array
    {
        $sorts = [];

        foreach (explode(',', (string) $value) as $part) {
            [$key, $dir] = array_pad(explode(':', trim($part), 2), 2, 'asc');

            if (in_array($key, $allowed, true) && ! in_array($key, array_column($sorts, 'key'), true)) {
                $sorts[] = ['key' => $key, 'dir' => $dir === 'desc' ? 'desc' : 'asc'];
            }
        }

        return $sorts === [] ? [['key' => self::DEFAULT_SORT, 'dir' => 'asc']] : array_slice($sorts, 0, self::MAX_SORTS);
    }

    /** Masa larian perubahan Cadangan terkini (semua baris satu larian berkongsi masa yg sama). */
    public static function latestChangeAt(): ?string
    {
        $value = RestockVerdictChange::query()->max('changed_at');

        return $value === null ? null : (string) $value;
    }

    /** Kedudukan kemendesakan sebuah Cadangan (0 = paling mendesak); tidak dikenali = terakhir. */
    public static function urgencyOf(string $verdict): int
    {
        $rank = array_search($verdict, self::VERDICT_ORDER, true);

        return $rank === false ? count(self::VERDICT_ORDER) - 1 : $rank;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool}  $filters
     */
    private static function applyCommon(Builder $query, array $filters, bool $withVerdict): void
    {
        if ($filters['category'] !== []) {
            $query->whereIn('category_code', $filters['category']);
        }

        if ($filters['store'] !== []) {
            $query->whereIn('store_code', $filters['store']);
        }

        if ($withVerdict && $filters['verdict'] !== []) {
            $query->whereIn('verdict', $filters['verdict']);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
