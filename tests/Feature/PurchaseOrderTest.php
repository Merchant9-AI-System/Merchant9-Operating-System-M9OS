<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function makePo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'vendor_code' => 'ACE',
            'vendor_name' => 'Test Vendor',
            'created_by' => 'Haniff',
        ]);
    }

    public function test_po_number_auto_generated(): void
    {
        $po = $this->makePo();
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $po->po_number);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
    }

    public function test_po_numbers_increment_sequentially(): void
    {
        $po1 = $this->makePo();
        $po2 = $this->makePo();
        $this->assertNotSame($po1->po_number, $po2->po_number);
    }

    public function test_total_amount_sums_line_items(): void
    {
        $po = $this->makePo();
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->lines()->create(['internal_code' => 'D2', 'qty_ordered' => 5, 'unit_cost' => 300]);

        $this->assertEquals(3000, $po->fresh()->load('lines')->total_amount);
    }

    public function test_full_status_lifecycle(): void
    {
        $po = $this->makePo();
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);

        $po->submitForApproval();
        $this->assertSame(PurchaseOrder::STATUS_PENDING_APPROVAL, $po->fresh()->status);

        $po->approve('Manager Haniff');
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $po->status);
        $this->assertSame('Manager Haniff', $po->approved_by);
        $this->assertNotNull($po->approved_at);

        $po->markAsSent();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_cannot_approve_a_draft_po(): void
    {
        $po = $this->makePo();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $po->approve('Manager Haniff');
    }

    public function test_cannot_submit_twice(): void
    {
        $po = $this->makePo();
        $po->submitForApproval();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $po->submitForApproval();
    }

    public function test_partial_receipt_updates_status_and_qty(): void
    {
        $po = $this->makePo();
        $line = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        GoodsReceipt::receive($po, [$line->id => 6], 'Staff Ali');

        $po->refresh();
        $line->refresh();
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $po->status);
        $this->assertSame(6, $line->qty_received);
        $this->assertSame(4, $line->qty_outstanding);
    }

    public function test_full_receipt_marks_po_received(): void
    {
        $po = $this->makePo();
        $l1 = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $l2 = $po->lines()->create(['internal_code' => 'D2', 'qty_ordered' => 5, 'unit_cost' => 300]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        GoodsReceipt::receive($po, [$l1->id => 10, $l2->id => 5], 'Staff Ali');

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
        $this->assertTrue($po->fresh()->load('lines')->isFullyReceived());
    }

    public function test_over_receive_is_rejected(): void
    {
        $po = $this->makePo();
        $line = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        GoodsReceipt::receive($po, [$line->id => 15], 'Staff Ali');
    }

    public function test_over_receive_does_not_partially_apply(): void
    {
        // Dua line dalam satu GRN, kedua line kedua over-receive - line pertama TAK patut
        // ter-update sebab seluruh operasi dalam satu transaksi DB.
        $po = $this->makePo();
        $l1 = $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $l2 = $po->lines()->create(['internal_code' => 'D2', 'qty_ordered' => 5, 'unit_cost' => 300]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        try {
            GoodsReceipt::receive($po, [$l1->id => 10, $l2->id => 999], 'Staff Ali');
        } catch (\Throwable $e) {
            // dijangka
        }

        $this->assertSame(0, $l1->fresh()->qty_received, 'Transaksi patut rollback sepenuhnya');
    }

    public function test_cannot_cancel_after_sent(): void
    {
        $po = $this->makePo();
        $po->lines()->create(['internal_code' => 'D1', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Mgr');
        $po->markAsSent();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $po->cancel();
    }

    public function test_can_cancel_draft(): void
    {
        $po = $this->makePo();
        $po->cancel();
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->fresh()->status);
    }
}
