<?php

namespace Tests\Feature;

use App\Support\RestockAnalysisCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RestockAnalysisCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('restock_by_size');
        Cache::forget('restock_by_weight');
    }

    public function test_by_size_returns_rows_with_valid_verdicts(): void
    {
        $recs = RestockAnalysisCalculator::bySize();
        $this->assertGreaterThan(0, $recs->count());

        $validVerdicts = [
            RestockAnalysisCalculator::VERDICT_SOLD_OUT,
            RestockAnalysisCalculator::VERDICT_RESTOCK,
            RestockAnalysisCalculator::VERDICT_OK,
            RestockAnalysisCalculator::VERDICT_OVERSTOCK,
            RestockAnalysisCalculator::VERDICT_NO_DATA,
        ];
        $this->assertTrue($recs->every(fn ($r) => in_array($r['verdict'], $validVerdicts, true)));
    }

    public function test_by_size_total_stock_matches_real_total(): void
    {
        $recs = RestockAnalysisCalculator::bySize();
        $expected = \App\Models\Jemisys\InventoryPiece::onHand()->realVendor()->count();
        $this->assertSame($expected, $recs->sum('current_stock'));
    }

    public function test_by_weight_total_stock_matches_real_total(): void
    {
        $recs = RestockAnalysisCalculator::byWeight();
        $expected = \App\Models\Jemisys\InventoryPiece::onHand()->realVendor()->count();
        $this->assertSame($expected, $recs->sum('current_stock'));
    }

    public function test_weight_bucket_boundaries(): void
    {
        $this->assertSame('0-1g', RestockAnalysisCalculator::weightBucket(0.5));
        $this->assertSame('1-2g', RestockAnalysisCalculator::weightBucket(1.0));
        $this->assertSame('3-5g', RestockAnalysisCalculator::weightBucket(4.99));
        $this->assertSame('50g+', RestockAnalysisCalculator::weightBucket(100));
        $this->assertSame('(tiada)', RestockAnalysisCalculator::weightBucket(null));
    }

    public function test_size_label_normalizes_trailing_zero(): void
    {
        $this->assertSame('17', RestockAnalysisCalculator::sizeLabel('17'));
        $this->assertSame('17.5', RestockAnalysisCalculator::sizeLabel('17.5'));
        $this->assertSame('(tiada)', RestockAnalysisCalculator::sizeLabel(''));
        $this->assertSame('(tiada)', RestockAnalysisCalculator::sizeLabel(null));
    }

    public function test_sold_out_verdict_only_when_stock_zero_and_has_velocity(): void
    {
        $recs = RestockAnalysisCalculator::bySize();
        $soldOut = $recs->where('verdict', RestockAnalysisCalculator::VERDICT_SOLD_OUT);
        $this->assertTrue($soldOut->every(fn ($r) => $r['current_stock'] === 0 && $r['velocity_per_month'] > 0));
    }
}
