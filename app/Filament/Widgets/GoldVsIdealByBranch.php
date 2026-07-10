<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class GoldVsIdealByBranch extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Berat Emas vs Ideal Setiap Cawangan';

    // protected string | array | int $columnSpan = 'full';

    protected function getData(): array
    {
        // retry() toleransi lock sementara (cth. antivirus scan selepas jemisys.db ditulis semula).
        $data = Cache::rememberForever('gold_vs_ideal_by_branch', function () {
            return retry(6, function () {
                $held = InventoryPiece::onHand()->realVendor()->physicalStore()
                    ->selectRaw('StoreCode, SUM(GoldWeight) / 1000 as kg')
                    ->groupBy('StoreCode')
                    ->pluck('kg', 'StoreCode');

                $ideal = Store::whereIn('StoreCode', $held->keys())->pluck('IdealGoldWeight916', 'StoreCode');

                return $held->sortDesc()->mapWithKeys(fn ($kg, $store) => [
                    $store => [
                        'held' => round((float) $kg, 1),
                        'ideal' => round((float) ($ideal[$store] ?? 0) / 1000, 1),
                    ],
                ])->toArray();
            }, 800);
        });

        $datasets = [[
            'label' => 'Dipegang (kg)',
            'data' => array_map(fn ($d) => $d['held'], array_values($data)),
            'backgroundColor' => '#7F77DD',
        ]];

        $hasIdeal = collect($data)->sum('ideal') > 0;
        if ($hasIdeal) {
            $datasets[] = [
                'label' => 'Ideal (kg)',
                'data' => array_map(fn ($d) => $d['ideal'], array_values($data)),
                'backgroundColor' => '#D85A30',
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_keys($data),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $data = Cache::get('gold_vs_ideal_by_branch', []);
        $hasIdeal = collect($data)->sum('ideal') > 0;

        return $hasIdeal
            ? 'Bar ungu = dipegang sekarang, bar oren = sasaran ideal (TblStore).'
            : '⚠️ TblStore.IdealGoldWeight916 belum diisi (semua 0) - papar berat dipegang sahaja. '.
                'Minta pengurusan tetapkan sasaran ideal setiap cawangan untuk aktifkan perbandingan.';
    }
}
