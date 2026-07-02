<?php

namespace Tests\Feature;

use App\Support\OrderRecommendationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderRecommendationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('order_recommendations');
    }

    public function test_returns_recommendations_with_positive_qty(): void
    {
        $recs = OrderRecommendationCalculator::recommendations();

        $this->assertGreaterThan(0, $recs->count());
        $this->assertTrue($recs->every(fn ($r) => $r['recommend_qty'] > 0));
    }

    public function test_all_rows_meet_eligibility_thresholds(): void
    {
        $recs = OrderRecommendationCalculator::recommendations();

        $this->assertTrue($recs->every(fn ($r) => $r['pieces_received'] >= OrderRecommendationCalculator::MIN_SAMPLE));
        $this->assertTrue($recs->every(fn ($r) => $r['sell_through_rate'] >= OrderRecommendationCalculator::ORDER_MIN_SELLTHRU));
    }

    public function test_sorted_by_vendor_then_qty_descending(): void
    {
        $recs = OrderRecommendationCalculator::recommendations();
        $byVendor = $recs->groupBy('vendor_code');

        foreach ($byVendor as $rows) {
            $qtys = $rows->pluck('recommend_qty')->all();
            $sorted = $qtys;
            rsort($sorted);
            $this->assertSame($sorted, $qtys, 'Dalam setiap vendor, recommend_qty patut menurun.');
        }
    }

    public function test_result_is_cached(): void
    {
        OrderRecommendationCalculator::recommendations();
        $this->assertTrue(Cache::has('order_recommendations'));
    }
}
