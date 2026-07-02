<?php

namespace Tests\Feature;

use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTransfer(): StockTransfer
    {
        return StockTransfer::create([
            'internal_code' => 'D1',
            'item_desc' => 'Cincin A',
            'from_store' => 'HQ',
            'to_store' => 'WM',
            'qty' => 1,
            'requested_by' => 'Haniff',
        ]);
    }

    public function test_transfer_number_auto_generated(): void
    {
        $t = $this->makeTransfer();
        $this->assertMatchesRegularExpression('/^TRF-\d{4}-\d{4}$/', $t->transfer_number);
        $this->assertSame(StockTransfer::STATUS_REQUESTED, $t->status);
        $this->assertNotNull($t->requested_at);
    }

    public function test_full_advance_flow(): void
    {
        $t = $this->makeTransfer();

        $t->advance('Staff Ali');
        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $t->fresh()->status);
        $this->assertNotNull($t->fresh()->in_transit_at);

        $t->advance('Staff Ali');
        $t->refresh();
        $this->assertSame(StockTransfer::STATUS_RECEIVED, $t->status);
        $this->assertSame('Staff Ali', $t->received_by);
        $this->assertNotNull($t->received_at);
    }

    public function test_cannot_advance_past_received(): void
    {
        $t = $this->makeTransfer();
        $t->advance('Ali');
        $t->advance('Ali');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $t->advance('Ali');
    }

    public function test_can_cancel_requested_transfer(): void
    {
        $t = $this->makeTransfer();
        $t->cancel();
        $this->assertSame(StockTransfer::STATUS_CANCELLED, $t->fresh()->status);
    }

    public function test_cannot_cancel_received_transfer(): void
    {
        $t = $this->makeTransfer();
        $t->advance('Ali');
        $t->advance('Ali');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $t->cancel();
    }
}
