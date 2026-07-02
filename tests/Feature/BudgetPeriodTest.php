<?php

namespace Tests\Feature;

use App\Models\BudgetPeriod;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_spend_is_zero_with_no_purchase_orders(): void
    {
        $b = BudgetPeriod::create(['period_label' => '2026-08', 'budget_amount' => 100000, 'created_by' => 'Haniff']);
        $this->assertEquals(0, $b->spent_amount);
        $this->assertEquals(0, $b->usage_percent);
        $this->assertFalse($b->isOverBudget());
    }

    public function test_spend_sums_purchase_orders_in_period(): void
    {
        $b = BudgetPeriod::create(['period_label' => now()->format('Y-m'), 'budget_amount' => 10000, 'created_by' => 'Haniff']);

        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]); // 1500

        $this->assertEquals(1500, $b->spent_amount);
        $this->assertEquals(15.0, round($b->usage_percent, 1));
    }

    public function test_cancelled_po_excluded_from_spend(): void
    {
        $b = BudgetPeriod::create(['period_label' => now()->format('Y-m'), 'budget_amount' => 10000, 'created_by' => 'Haniff']);

        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->cancel();

        $this->assertEquals(0, $b->spent_amount);
    }

    public function test_over_budget_detection(): void
    {
        $b = BudgetPeriod::create(['period_label' => now()->format('Y-m'), 'budget_amount' => 1000, 'created_by' => 'Haniff']);

        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]); // 1500 > 1000 budget

        $this->assertTrue($b->isOverBudget());
        $this->assertEquals(0, $b->remaining_amount);
    }

    public function test_category_scoped_budget_only_counts_matching_lines(): void
    {
        $b = BudgetPeriod::create(['period_label' => now()->format('Y-m'), 'category_code' => 'RT', 'budget_amount' => 10000, 'created_by' => 'Haniff']);

        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'category_code' => 'RT', 'qty_ordered' => 10, 'unit_cost' => 150]); // 1500, RT
        $po->lines()->create(['internal_code' => 'D2', 'category_code' => 'CE', 'qty_ordered' => 5, 'unit_cost' => 300]); // 1500, CE - tak dikira

        $this->assertEquals(1500, $b->spent_amount);
    }
}
