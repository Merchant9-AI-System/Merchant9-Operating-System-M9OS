<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\PhysicalGoldCategory;
use App\Models\PhysicalGoldPurity;
use App\Models\PhysicalGoldReport;
use Illuminate\Support\Collection;

/**
 * Menjambatan borang "mesra" (seksyen tetap per kategori, tiada pilihan kategori/purity manual
 * utk kebanyakan seksyen) dgn baris PhysicalGoldReportLine sebenar (yg masih perlukan
 * physical_gold_category_id + physical_gold_purity_id utk pengiraan PhysicalGoldReportCalculator
 * berfungsi tanpa diubah). Kod kategori & kod ketulenan "930" dirujuk terus (bukan config) sbb
 * ini struktur laporan tetap yg disahkan pengguna, bukan sesuatu yg berubah kerap.
 */
class PhysicalGoldReportLineMapper
{
    public const BLENDED_PURITY_CODE = '930';

    public static function defaultUsedGoldHqRows(): array
    {
        return static::defaultPurityRows();
    }

    public static function defaultGdnRows(): array
    {
        return static::gradedPurities()
            ->map(fn (PhysicalGoldPurity $p) => [
                'purity_code' => $p->code,
                'date_range_from' => null,
                'date_range_to' => null,
                'gross_weight' => null,
                'remarks' => null,
            ])
            ->all();
    }

    /** Setiap cawangan pra-isi dgn pecahan ketulenan sendiri (nested repeater "purity_lines")
     * - sepadan Used Gold at HQ, atas permintaan eksplisit ("stock at branch tunjuk ikut
     * cawangan JUGA ikut ketulenan", bukan satu baris "blended" sahaja spt sebelum ni). */
    public static function defaultBranchRows(): array
    {
        return static::branches()
            ->map(fn (Store $s) => [
                'store_code' => $s->StoreCode,
                'store_label' => $s->StoreCode,
                'purity_lines' => static::defaultPurityRows(),
            ])
            ->all();
    }

    /** Stock at HQ - HQ SATU lokasi sahaja (tiada dimensi cawangan), jadi cukup pecahan
     * ketulenan terus (struktur SAMA dgn defaultUsedGoldHqRows(), bukan nested spt branch). */
    public static function defaultStockHqRows(): array
    {
        return static::defaultPurityRows();
    }

    /** Set baris ketulenan asas kosong - dikongsi Used Gold at HQ, Stock at Branch (per
     * cawangan) & Stock at HQ. */
    protected static function defaultPurityRows(): array
    {
        return static::gradedPurities()
            ->map(fn (PhysicalGoldPurity $p) => ['purity_code' => $p->code, 'gross_weight' => null, 'remarks' => null])
            ->all();
    }

    /** 8 gred ketulenan ASAS (bukan 930/varian) - set tetap pra-isi Used Gold at HQ & GDN Pending. */
    protected static function gradedPurities(): Collection
    {
        return PhysicalGoldPurity::query()
            ->where('active', true)
            ->where('is_base_purity', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Semua ketulenan boleh PILIH via Select (8 gred asas + varian cth. "916 - YS"/"916 - KIV"),
     * kecuali 930 (faktor "blended" - automatik sahaja, bukan utk kategori bergred-purity ni).
     */
    public static function selectablePurities(): Collection
    {
        return PhysicalGoldPurity::query()
            ->where('active', true)
            ->where('code', '!=', self::BLENDED_PURITY_CODE)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Jumlah Berat (g) & Berat Tulen (g) bagi SATU set baris ketulenan (footer repeater Used
     * Gold at HQ/Stock at HQ/GDN Pending/nested purity_lines Stock at Branch) - dikira terus
     * drpd state borang LIVE (bukan DB), formula sama dgn preview pure_weight setiap baris
     * (gross_weight x purityFactorFor(purity_code)), supaya jumlah SENTIASA padan jumlah baris
     * individu yg dipaparkan, walaupun blm disimpan.
     *
     * @param  ?array<int, array{purity_code?: ?string, gross_weight?: mixed}>  $rows
     * @return array{gross: float, pure: float}
     */
    public static function sumPurityRows(?array $rows): array
    {
        $gross = 0.0;
        $pure = 0.0;

        foreach ($rows ?? [] as $row) {
            $rowGross = (float) ($row['gross_weight'] ?? 0);
            $gross += $rowGross;
            $pure += $rowGross * static::purityFactorFor($row['purity_code'] ?? null);
        }

        return ['gross' => round($gross, 2), 'pure' => round($pure, 2)];
    }

    /** Memoized per request - dipanggil berulang kali oleh preview live() setiap taip/render baris. */
    public static function purityFactorFor(?string $code): float
    {
        if (blank($code)) {
            return 1.0;
        }

        static $factors = null;
        $factors ??= PhysicalGoldPurity::pluck('factor', 'code');

        return (float) ($factors[$code] ?? 1.0);
    }

    protected static function branches(): Collection
    {
        return Store::query()
            ->where('Active', 1)
            ->whereNotIn('StoreCode', ['HQ', 'SECURITY'])
            ->orderBy('StoreCode')
            ->get();
    }

    /**
     * Stok fizikal SEMASA (jemisys_inventory_mirror, onHand()) pecah ikut cawangan + ketulenan -
     * sumber "Tarik Data Inventori" (Stock at Branch/HQ). ClassCode dipadankan terus kpd kod
     * ketulenan GRED ASAS (gradedPurities) - item bukan emas (cth. "PERAK 925"/"960 Silver",
     * disahkan wujud sebenar dlm data Tinker sesi ni) jatuh keluar SENDIRI drpd padanan (BUKAN
     * turut dikira sbg 0 - baris tsb terus tiada dlm hasil, byStore()->get($purity) natural
     * pulang null utk kombinasi store+purity yg tiada). GoldWeight (bukan QtyOnHand) sbg
     * "Berat (g)" - QtyOnHand cuma 0/1 (satu keping).
     *
     * @return Collection<string, Collection<string, float>> StoreCode (trim) => [purity_code => jumlah GoldWeight]
     */
    public static function inventoryStockByStoreAndPurity(): Collection
    {
        $validCodes = static::gradedPurities()->pluck('code')->all();

        return InventoryPiece::query()
            ->onHand()
            ->selectRaw('StoreCode, ClassCode, SUM(GoldWeight) as total_weight')
            ->groupBy('StoreCode', 'ClassCode')
            ->get()
            ->filter(fn ($row) => in_array(trim((string) $row->ClassCode), $validCodes, true))
            ->groupBy(fn ($row) => trim((string) $row->StoreCode))
            ->map(fn (Collection $rows) => $rows
                ->groupBy(fn ($row) => trim((string) $row->ClassCode))
                ->map(fn (Collection $group) => (float) $group->sum('total_weight')));
    }

    /** Susun semula state borang drpd baris sedia ada (utk EditRecord::mutateFormDataBeforeFill()). */
    public static function formStateFromReport(PhysicalGoldReport $report): array
    {
        $lines = $report->lines()->with(['category', 'purity', 'store'])->get();

        $usedGold = $lines->where('category.code', 'USED_GOLD_HQ')->values();
        $gdn = $lines->where('category.code', 'GDN_PENDING')->values();
        $branch = $lines->where('category.code', 'STOCK_BRANCH')->values();
        $hq = $lines->where('category.code', 'STOCK_HQ')->values();
        $newStock = $lines->where('category.code', 'NEW_STOCK_SUPPLIER')->values();
        $outstanding = $lines->where('category.code', 'SUPPLIER_OUTSTANDING')->values();

        return [
            'used_gold_hq_lines' => static::mergeGradedRows($usedGold, fn ($line) => [
                'purity_code' => $line->purity?->code,
                'gross_weight' => $line->gross_weight,
                'remarks' => $line->remarks,
            ], fn ($purity) => ['purity_code' => $purity->code, 'gross_weight' => null, 'remarks' => null]),

            'gdn_pending_lines' => static::mergeGradedRows($gdn, fn ($line) => [
                'purity_code' => $line->purity?->code,
                'date_range_from' => $line->date_range_from,
                'date_range_to' => $line->date_range_to,
                'gross_weight' => $line->gross_weight,
                'remarks' => $line->remarks,
            ], fn ($purity) => [
                'purity_code' => $purity->code,
                'date_range_from' => null,
                'date_range_to' => null,
                'gross_weight' => null,
                'remarks' => null,
            ]),

            'stock_branch_lines' => static::branches()->map(function (Store $s) use ($branch) {
                $linesForStore = $branch->where('store_code', $s->StoreCode)->values();

                return [
                    'store_code' => $s->StoreCode,
                    'store_label' => $s->StoreCode,
                    'purity_lines' => static::mergeGradedRows($linesForStore, fn ($line) => [
                        'purity_code' => $line->purity?->code,
                        'gross_weight' => $line->gross_weight,
                        'remarks' => $line->remarks,
                    ], fn ($purity) => ['purity_code' => $purity->code, 'gross_weight' => null, 'remarks' => null]),
                ];
            })->values()->all(),

            'stock_hq_lines' => static::mergeGradedRows($hq, fn ($line) => [
                'purity_code' => $line->purity?->code,
                'gross_weight' => $line->gross_weight,
                'remarks' => $line->remarks,
            ], fn ($purity) => ['purity_code' => $purity->code, 'gross_weight' => null, 'remarks' => null]),

            'new_stock_lines' => $newStock->map(fn ($line) => [
                'vendor_code' => $line->vendor_code,
                'gross_weight' => $line->gross_weight,
                'workmanship_amount' => $line->workmanship_amount,
                'gold_price_per_gram' => $line->gold_price_per_gram,
            ])->all(),

            'supplier_outstanding_lines' => $outstanding->map(fn ($line) => [
                'vendor_code' => $line->vendor_code,
                'payable_gross_weight' => $line->payable_gross_weight,
                'receivable_gross_weight' => $line->receivable_gross_weight,
            ])->all(),
        ];
    }

    /**
     * Padankan baris sedia ada dgn set ketulenan tetap (base slot pertama tanpa remarks utk
     * setiap ketulenan), lebihan (cth. "916 - YS"/"916 - KIV") ditambah selepas sbg baris extra -
     * supaya set tetap sentiasa terpapar walau kosong, TANPA hilang baris istimewa yg dicipta.
     */
    protected static function mergeGradedRows(Collection $lines, \Closure $toRow, \Closure $blankRow): array
    {
        $remaining = $lines->values();
        $rows = [];

        foreach (static::gradedPurities() as $purity) {
            $index = $remaining->search(fn ($l) => $l->physical_gold_purity_id === $purity->id && blank($l->remarks));

            if ($index !== false) {
                $line = $remaining->pull($index);
                $remaining = $remaining->values();
                $rows[] = $toRow($line);
            } else {
                $rows[] = $blankRow($purity);
            }
        }

        foreach ($remaining as $line) {
            $rows[] = $toRow($line);
        }

        return $rows;
    }

    /**
     * Padam semua baris sedia ada & cipta semula drpd state borang - pendekatan "recompute
     * semula" yg sama dgn konvensyen sedia ada modul ni (bukan diff baris satu-satu).
     */
    public static function syncLinesFromFormState(PhysicalGoldReport $report, array $data): void
    {
        $report->lines()->delete();

        $categoryIds = PhysicalGoldCategory::pluck('id', 'code');
        $purityIds = PhysicalGoldPurity::pluck('id', 'code');
        $blendedPurityId = $purityIds[self::BLENDED_PURITY_CODE] ?? null;

        foreach ($data['used_gold_hq_lines'] ?? [] as $row) {
            if (blank($row['gross_weight'] ?? null)) {
                continue;
            }

            $report->lines()->create([
                'physical_gold_category_id' => $categoryIds['USED_GOLD_HQ'] ?? null,
                'physical_gold_purity_id' => $purityIds[$row['purity_code']] ?? null,
                'gross_weight' => $row['gross_weight'],
                'remarks' => $row['remarks'] ?? null,
            ]);
        }

        foreach ($data['gdn_pending_lines'] ?? [] as $row) {
            if (blank($row['gross_weight'] ?? null)) {
                continue;
            }

            $report->lines()->create([
                'physical_gold_category_id' => $categoryIds['GDN_PENDING'] ?? null,
                'physical_gold_purity_id' => $purityIds[$row['purity_code']] ?? null,
                'date_range_from' => $row['date_range_from'] ?? null,
                'date_range_to' => $row['date_range_to'] ?? null,
                'gross_weight' => $row['gross_weight'],
                'remarks' => $row['remarks'] ?? null,
            ]);
        }

        // Pecahan ketulenan per cawangan (nested "purity_lines") - BUKAN lagi satu baris
        // "blended" per cawangan, atas permintaan eksplisit (rujuk dokblok defaultBranchRows()).
        foreach ($data['stock_branch_lines'] ?? [] as $branchRow) {
            foreach ($branchRow['purity_lines'] ?? [] as $row) {
                if (blank($row['gross_weight'] ?? null)) {
                    continue;
                }

                $report->lines()->create([
                    'physical_gold_category_id' => $categoryIds['STOCK_BRANCH'] ?? null,
                    'physical_gold_purity_id' => $purityIds[$row['purity_code']] ?? null,
                    'store_code' => $branchRow['store_code'] ?? null,
                    'gross_weight' => $row['gross_weight'],
                    'remarks' => $row['remarks'] ?? null,
                ]);
            }
        }

        // Stock at HQ - pecahan ketulenan terus (tiada dimensi cawangan, rujuk dokblok
        // defaultStockHqRows()), struktur SAMA dgn Used Gold at HQ di atas.
        foreach ($data['stock_hq_lines'] ?? [] as $row) {
            if (blank($row['gross_weight'] ?? null)) {
                continue;
            }

            $report->lines()->create([
                'physical_gold_category_id' => $categoryIds['STOCK_HQ'] ?? null,
                'physical_gold_purity_id' => $purityIds[$row['purity_code']] ?? null,
                'store_code' => 'HQ',
                'gross_weight' => $row['gross_weight'],
                'remarks' => $row['remarks'] ?? null,
            ]);
        }

        foreach ($data['new_stock_lines'] ?? [] as $row) {
            if (blank($row['vendor_code'] ?? null) && blank($row['gross_weight'] ?? null)) {
                continue;
            }

            $report->lines()->create([
                'physical_gold_category_id' => $categoryIds['NEW_STOCK_SUPPLIER'] ?? null,
                'physical_gold_purity_id' => $blendedPurityId,
                'vendor_code' => $row['vendor_code'] ?? null,
                'gross_weight' => $row['gross_weight'] ?? null,
                'workmanship_amount' => $row['workmanship_amount'] ?? null,
                'gold_price_per_gram' => $row['gold_price_per_gram'] ?? null,
            ]);
        }

        foreach ($data['supplier_outstanding_lines'] ?? [] as $row) {
            if (blank($row['vendor_code'] ?? null)) {
                continue;
            }

            $report->lines()->create([
                'physical_gold_category_id' => $categoryIds['SUPPLIER_OUTSTANDING'] ?? null,
                'physical_gold_purity_id' => $blendedPurityId,
                'vendor_code' => $row['vendor_code'],
                'payable_gross_weight' => $row['payable_gross_weight'] ?? null,
                'receivable_gross_weight' => $row['receivable_gross_weight'] ?? null,
            ]);
        }
    }

    /** Kunci state borang (bukan lajur PhysicalGoldReport sebenar) - JANGAN hantar terus ke create()/update(). */
    public static function virtualKeys(): array
    {
        return [
            'used_gold_hq_lines',
            'gdn_pending_lines',
            'stock_branch_lines',
            'stock_hq_lines',
            'new_stock_lines',
            'supplier_outstanding_lines',
        ];
    }
}
