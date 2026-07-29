<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Statistik utk App\Filament\Pages\RestockByCategory - diisi via getWidgetData() pada page
 * (rujuk https://filamentphp.com/docs/5.x/navigation/custom-pages#adding-widgets-to-pages).
 * $stats dikira OLEH PAGE drpd set data tertapis SEMASA (kategori + filter + carian) - widget
 * ni sendiri TIDAK buat query, sekadar papar apa yg dihantar, supaya sentiasa selari dgn jadual.
 *
 * getWidgetData() cuma isi $stats SEKALI semasa mount pertama (widget ni komponen Livewire
 * berasingan drpd page - TIDAK remount automatik bila jadual/penapis induk berubah), jadi
 * WAJIB dengar event #[On(...)] yg page dispatch secara eksplisit setiap kali penapis/carian
 * betul2 berubah (rujuk RestockByCategory::dispatchStatsRefresh()) - tanpa ni, widget "statik"
 * kekal tunjuk state kosong walau kategori dah dipilih & jadual dah papar data.
 */
class RestockByCategoryStats extends StatsOverviewWidget
{
    public array $stats = [];

    #[On('restock-by-category-stats-updated')]
    public function refreshStats(array $stats): void
    {
        $this->stats = $stats;
    }

    protected function getStats(): array
    {
        if (! ($this->stats['has_category'] ?? false)) {
            return [
                Stat::make('Restock ikut Kategori', 'Pilih Kategori dahulu')
                    ->description('Statistik akan terpapar selepas Kategori dipilih di penapis jadual.')
                    ->color('gray'),
            ];
        }

        return [
            Stat::make('Jumlah Design', number_format($this->stats['total'] ?? 0)),
            Stat::make('Perlu Restock', number_format($this->stats['needs_restock'] ?? 0))
                ->color(($this->stats['needs_restock'] ?? 0) > 0 ? 'danger' : 'success'),
            Stat::make('Jumlah Unit Gap', number_format($this->stats['total_gap'] ?? 0))
                ->color(($this->stats['total_gap'] ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make('Cawangan Terjejas', number_format($this->stats['branches_affected'] ?? 0)),
        ];
    }
}
