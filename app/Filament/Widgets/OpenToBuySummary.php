<?php

namespace App\Filament\Widgets;

use App\Models\BudgetPeriod;
use App\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenToBuySummary extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $currentPeriod = now()->format('Y-m');
        $budget = BudgetPeriod::where('period_label', $currentPeriod)->whereNull('category_code')->first();

        $openStatuses = [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED];
        $openPos = PurchaseOrder::with('lines')->whereIn('status', $openStatuses)->get();
        $openValue = $openPos->sum(fn ($po) => $po->total_amount);

        $budgetStat = $budget
            ? 'RM '.number_format($budget->spent_amount / 1_000_000, 2).'j / '.number_format($budget->budget_amount / 1_000_000, 2).'j'
            : 'Belum ditetapkan';

        return [
            Stat::make('Bajet Beli Bulan Ini', $budgetStat)
                ->description($budget ? round($budget->usage_percent, 0).'% digunakan' : "Tetapkan bajet {$currentPeriod} di Open-to-Buy")
                ->color($budget && $budget->isOverBudget() ? 'danger' : 'primary'),

            Stat::make('PO Terbuka (belum terima)', $openPos->count().' PO')
                ->description('RM '.number_format($openValue / 1000, 0).'k belum diterima')
                ->color('warning'),
        ];
    }
}
