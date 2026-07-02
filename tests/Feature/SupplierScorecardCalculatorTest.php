<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Support\SupplierScorecardCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SupplierScorecardCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('supplier_scorecard');
    }

    public function test_empty_when_no_purchase_orders(): void
    {
        $sc = SupplierScorecardCalculator::scorecard();
        $this->assertSame(0, $sc->count());
    }

    public function test_computes_fill_rate_and_spend(): void
    {
        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $line = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();
        GoodsReceipt::receive($po, [$line->id => 8], 'Ali');

        $sc = SupplierScorecardCalculator::scorecard();
        $ace = $sc->firstWhere('vendor_code', 'ACE');

        $this->assertNotNull($ace);
        $this->assertSame(1, $ace['total_po']);
        $this->assertEquals(1500, $ace['total_spend']);
        $this->assertSame(10, $ace['total_ordered']);
        $this->assertSame(8, $ace['total_received']);
        $this->assertSame(80.0, $ace['fill_rate']);
    }

    public function test_lead_time_computed_only_for_fully_received_po(): void
    {
        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $line = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        // Belum Received sepenuhnya - lead time tak patut dikira lagi.
        GoodsReceipt::receive($po, [$line->id => 5], 'Ali');
        $sc = SupplierScorecardCalculator::scorecard();
        $this->assertNull($sc->firstWhere('vendor_code', 'ACE')['avg_lead_time_days']);

        Cache::forget('supplier_scorecard');
        // Lengkapkan penerimaan - sekarang Received, lead time patut dikira.
        GoodsReceipt::receive($po, [$line->id => 5], 'Ali');
        $sc = SupplierScorecardCalculator::scorecard();
        $ace = $sc->firstWhere('vendor_code', 'ACE');
        $this->assertNotNull($ace['avg_lead_time_days']);
        $this->assertSame(1, $ace['po_received_count']);
    }

    public function test_cancelled_po_excluded(): void
    {
        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->cancel();

        $sc = SupplierScorecardCalculator::scorecard();
        $this->assertNull($sc->firstWhere('vendor_code', 'ACE'));
    }

    public function test_sorted_by_spend_descending(): void
    {
        $poA = PurchaseOrder::create(['vendor_code' => 'ACE', 'created_by' => 'Haniff']);
        $poA->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 100]); // 1000

        $poB = PurchaseOrder::create(['vendor_code' => 'AGJ', 'created_by' => 'Haniff']);
        $poB->lines()->create(['internal_code' => 'D2', 'qty_ordered' => 10, 'unit_cost' => 500]); // 5000

        $sc = SupplierScorecardCalculator::scorecard();
        $this->assertSame('AGJ', $sc->first()['vendor_code']);
    }
}
