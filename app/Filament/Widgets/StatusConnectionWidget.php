<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Ringkasan status sambungan JEMiSys (checks + cermin tempatan) sbg Stat cards di bahagian
 * atas JemisysConnectionStatus. Data dibekalkan drpd Page::getWidgetData() (bukan widget
 * query sendiri) - elak duplicate query sbb page tu dah kira semuanya (rujuk
 * app/Filament/Pages/JemisysConnectionStatus.php).
 */
class StatusConnectionWidget extends StatsOverviewWidget
{
    /** @var array<string, array{label: string, status: string, detail: string, ms: ?float}> */
    public array $checks = [];

    /** @var array<string, int> */
    public array $mirrors = [];

    public ?string $lastSyncedAt = null;

    protected function getStats(): array
    {
        $failed = collect($this->checks)->filter(fn ($check) => $check['status'] === 'fail');

        return [
            Stat::make('Status Sambungan', $failed->isNotEmpty() ? 'Gagal' : 'Sihat')
                ->description($failed->isNotEmpty()
                    ? $failed->count().' semakan gagal - rujuk senarai di bawah'
                    : 'Semua semakan lulus')
                ->descriptionIcon($failed->isNotEmpty() ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color($failed->isNotEmpty() ? 'danger' : 'success'),

            Stat::make('Jumlah Baris Cermin Tempatan', number_format(array_sum($this->mirrors)))
                ->description(collect($this->mirrors)
                    ->map(fn ($count, $label) => "{$label}: ".number_format($count))
                    ->implode(' · '))
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('primary'),

            Stat::make(
                'Segerak Terakhir',
                $this->lastSyncedAt ? Carbon::parse($this->lastSyncedAt)->diffForHumans() : 'Belum pernah disegerak'
            )
                ->descriptionIcon('heroicon-m-clock')
                ->color($this->lastSyncedAt ? 'success' : 'gray'),
        ];
    }
}
