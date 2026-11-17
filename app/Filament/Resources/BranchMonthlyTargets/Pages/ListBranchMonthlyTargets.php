<?php

namespace App\Filament\Resources\BranchMonthlyTargets\Pages;

use App\Filament\Resources\BranchMonthlyTargets\BranchMonthlyTargetResource;
use App\Models\BranchMonthlyTarget;
use App\Support\BranchActivityCalculator;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListBranchMonthlyTargets extends ListRecords
{
    protected static string $resource = BranchMonthlyTargetResource::class;

    /** Cawangan jualan yang BELUM ada sasaran bagi bulan semasa - supaya HQ nampak yang tertinggal. */
    public function getSubheading(): string|Htmlable|null
    {
        $month = BranchMonthlyTarget::currentMonth();
        $withTarget = BranchMonthlyTarget::query()->whereDate('month', $month->toDateString())->pluck('store_code')
            ->map(fn (string $code): string => BranchMonthlyTarget::normalizeStore($code))->all();

        $missing = collect(BranchActivityCalculator::branches())
            ->map(fn (string $code): string => BranchMonthlyTarget::normalizeStore($code))
            ->reject(fn (string $code): bool => in_array($code, $withTarget, true))
            ->values();

        $label = $month->locale('ms')->translatedFormat('F Y');

        return $missing->isEmpty()
            ? "Semua cawangan sudah ada sasaran untuk {$label}."
            : "Belum ada sasaran untuk {$label}: ".$missing->implode(', ').'.';
    }
}
