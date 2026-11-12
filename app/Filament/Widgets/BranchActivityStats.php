<?php

namespace App\Filament\Widgets;

use App\Support\BranchActivityCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Aktiviti Cawangan: Receive (barang masuk + demand request dihantar, bulan ini), Branch Request (cawangan lain minta daripada kita, belum
 * selesai), Slow Moving & Fast Selling (bilangan design). Definisi tepat: rujuk App\Support\BranchActivityCalculator.
 *
 * Skop: user ADA cawangan (store_code) nampak cawangan sendiri sahaja; user TANPA cawangan (HQ/CEO/manager/super_admin) boleh pilih
 * cawangan atau "Semua cawangan". Kebenaran widget (Shield): permission View:BranchActivityStats - diberi kepada role oleh
 * Database\Seeders\BranchActivityWidgetPermissionSeeder (leader_branch, manager, ceo, super_admin); role lain boleh ditambah di Shield.
 */
class BranchActivityStats extends StatsOverviewWidget
{
    use HasWidgetShield;

    /** Nilai pilihan "semua cawangan" (kosong tidak boleh jadi kunci Select). */
    public const ALL_BRANCHES = 'all';

    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    /** @var array{branch?: ?string} */
    public ?array $data = ['branch' => self::ALL_BRANCHES];

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('branch')
                    ->label('Cawangan')
                    ->options($this->branchOptions())
                    ->default(self::ALL_BRANCHES)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visible(fn (): bool => $this->userBranch() === null)
                    ->columnSpan(['default' => 'full', 'sm' => 1]),
                $this->getSectionContentComponent(),
            ])
            ->statePath('data');
    }

    /** Cawangan user (store_code, trim) atau null bila user tiada cawangan. */
    public function userBranch(): ?string
    {
        $store = trim((string) Auth::user()?->store_code);

        return $store === '' ? null : $store;
    }

    /** Cawangan yg dipaparkan: cawangan user, atau pilihan sah (selain itu "semua cawangan" = null). */
    public function selectedBranch(): ?string
    {
        if (($own = $this->userBranch()) !== null) {
            return $own;
        }

        $picked = (string) ($this->data['branch'] ?? self::ALL_BRANCHES);

        return in_array($picked, BranchActivityCalculator::branches(), true) ? $picked : null;
    }

    /** @return array<string, string> */
    protected function branchOptions(): array
    {
        $branches = BranchActivityCalculator::branches();

        return [self::ALL_BRANCHES => 'Semua cawangan', ...array_combine($branches, $branches)];
    }

    protected function getHeading(): ?string
    {
        return 'Aktiviti Cawangan: '.($this->selectedBranch() ?? 'Semua cawangan');
    }

    protected function getDescription(): ?string
    {
        return 'Receive bulan ini; Branch Request belum selesai; Slow/Fast ikut jualan '
            .BranchActivityCalculator::SLOW_WINDOW_DAYS.' / '.BranchActivityCalculator::FAST_WINDOW_DAYS.' hari.';
    }

    protected function getStats(): array
    {
        $figures = BranchActivityCalculator::forStore($this->selectedBranch());
        $receive = $figures['receive'];
        $request = $figures['branch_request'];
        $transfersUrl = route('filament.admin.resources.stock-transfers.index');

        return [
            Stat::make('Receive', number_format($receive['qty']).' unit')
                ->description("Transfer masuk {$receive['transfer_qty']} (belum diterima) + demand dihantar {$receive['demand_qty']} - bulan ini")
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->url($transfersUrl),

            Stat::make('Branch Request', number_format($request['qty']).' unit')
                ->description($this->branchRequestDescription($request['transfers']))
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color($request['qty'] > 0 ? 'warning' : 'gray')
                ->url($transfersUrl),

            Stat::make('Slow Moving', number_format($figures['slow']['designs']).' design')
                ->description(number_format($figures['slow']['pieces']).' piece - ada stok, tiada jualan '.BranchActivityCalculator::SLOW_WINDOW_DAYS.' hari, stok >'.BranchActivityCalculator::SLOW_MIN_AGE_DAYS.' hari')
                ->descriptionIcon('heroicon-m-clock')
                ->color($figures['slow']['designs'] > 0 ? 'danger' : 'gray'),

            Stat::make('Fast Selling', number_format($figures['fast']['designs']).' design')
                ->description(number_format($figures['fast']['pieces']).' piece terjual - '.BranchActivityCalculator::FAST_MIN_SOLD.'+ dalam '.BranchActivityCalculator::FAST_WINDOW_DAYS.' hari')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('success'),
        ];
    }

    private function branchRequestDescription(int $transfers): string
    {
        $branch = $this->selectedBranch();

        return $branch === null
            ? "{$transfers} transfer antara cawangan belum selesai"
            : "Cawangan lain minta daripada {$branch}: {$transfers} transfer belum selesai";
    }
}
