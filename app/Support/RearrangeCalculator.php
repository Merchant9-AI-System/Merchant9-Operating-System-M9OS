<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Port terus daripada analytics.py rearrange_recommendations() (Flask/Python) - kekalkan
 * algoritma sama supaya keputusan konsisten merentas kedua-dua sistem.
 *
 * Formula: kalau satu cawangan ada >=2 unit sesuatu design (donor, simpan 1 kekal), dan
 * cawangan lain SOLD OUT (stok=0) TAPI pernah jual design itu (receiver), cadangkan pindah
 * 1 unit setiap masa daripada donor paling banyak surplus ke receiver paling laris (greedy),
 * sehingga surplus/receiver habis. Kedai online (WEB/web) dikecualikan.
 */
class RearrangeCalculator
{
    public static function recommendations(): Collection
    {
        // Cache guna array biasa (bukan Collection) - elak isu unserialize __PHP_Incomplete_Class
        // yang berlaku bila cache ditulis dari konteks CLI dibaca semula dari konteks web (php
        // artisan serve) atau sebaliknya. Array/scalar biasa tiada pergantungan class semasa
        // unserialize, jadi selamat merentas konteks proses.
        $plain = Cache::rememberForever('rearrange_recommendations', function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        });

        return collect($plain);
    }

    protected static function compute(): Collection
    {
        // Agregat stock+sold per (InternalCode, StoreCode) - sepadan df.groupby Python.
        $rows = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold')
            ->groupBy('InternalCode', 'StoreCode')
            ->get();

        // Maklumat design (utk paparan) - ambil sekali sahaja per InternalCode.
        $meta = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode, MAX(VendorCode) as VendorCode')
            ->groupBy('InternalCode')
            ->get()
            ->keyBy('InternalCode');

        $categoryNames = Category::pluck('Description', 'CategoryCode');
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        $recs = collect();

        foreach ($rows->groupBy('InternalCode') as $code => $sub) {
            $donors = $sub->filter(fn ($r) => $r->stock >= 2)->sortByDesc('stock')->values();
            $receivers = $sub->filter(fn ($r) => $r->stock == 0 && $r->sold >= 1)->sortByDesc('sold')->values();

            if ($donors->isEmpty() || $receivers->isEmpty()) {
                continue;
            }

            // surplus boleh diberi = stock - 1 (kekalkan sekurang-kurangnya 1 di donor)
            $surplus = $donors->mapWithKeys(fn ($d) => [$d->StoreCode => (int) $d->stock - 1]);
            $moves = [];

            foreach ($receivers as $rc) {
                $donorStore = $surplus->filter(fn ($v) => $v > 0)->sortDesc()->keys()->first();
                if ($donorStore === null) {
                    break;
                }
                $surplus[$donorStore] -= 1;
                $moves[] = [$donorStore, $rc->StoreCode];
            }

            if (empty($moves)) {
                continue;
            }

            // Gabung pindahan sama from->to
            $agg = [];
            foreach ($moves as [$frm, $to]) {
                $key = "{$frm}|{$to}";
                $agg[$key] = ($agg[$key] ?? 0) + 1;
            }
            $suggestion = collect($agg)->map(function ($qty, $key) {
                [$frm, $to] = explode('|', $key);

                return "{$qty} unit: {$frm} -> {$to}";
            })->implode(', ');

            $m = $meta->get($code);
            $recs->push([
                'InventoryCode' => $code, // primary key InventoryPiece - unik ikut design
                'internal_code' => $code,
                'item_desc' => $m->Description ?? '',
                'category' => $m->CategoryCode ?? '',
                'category_name' => $categoryNames[$m->CategoryCode ?? ''] ?? ($m->CategoryCode ?? ''),
                'vendor_code' => $m->VendorCode ?? '',
                'vendor_name' => $vendorNames[$m->VendorCode ?? ''] ?? ($m->VendorCode ?? ''),
                'receivers' => $receivers->map(fn ($r) => "{$r->StoreCode} (pernah jual {$r->sold})")->implode(', '),
                'donors' => $donors->map(fn ($d) => "{$d->StoreCode} (stok {$d->stock})")->implode(', '),
                'total_move' => array_sum($agg),
                'suggestion' => $suggestion,
            ]);
        }

        return $recs->sortByDesc('total_move')->values();
    }
}
