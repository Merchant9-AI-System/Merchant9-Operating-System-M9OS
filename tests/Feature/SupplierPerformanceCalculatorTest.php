<?php

namespace Tests\Feature;

use App\Support\SupplierPerformanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SupplierPerformanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('supplier_performance_jemisys');
    }

    public function test_returns_vendors_with_min_sample(): void
    {
        $perf = SupplierPerformanceCalculator::performance();
        $this->assertGreaterThan(0, $perf->count());
        $this->assertTrue($perf->every(fn ($p) => $p['pieces_received'] >= 3));
    }

    public function test_sorted_by_avg_unit_cost_descending(): void
    {
        $perf = SupplierPerformanceCalculator::performance();
        $costs = $perf->pluck('avg_unit_cost')->all();
        $sorted = $costs;
        rsort($sorted);
        $this->assertSame($sorted, $costs);
    }

    public function test_margin_null_when_no_sales_amount_sample(): void
    {
        $perf = SupplierPerformanceCalculator::performance();
        $noSample = $perf->where('margin_sample_size', 0);
        $this->assertTrue($noSample->every(fn ($p) => $p['margin_pct'] === null));
    }

    public function test_result_is_cached(): void
    {
        SupplierPerformanceCalculator::performance();
        $this->assertTrue(Cache::has('supplier_performance_jemisys'));
    }
}
