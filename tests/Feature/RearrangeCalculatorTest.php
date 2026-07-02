<?php

namespace Tests\Feature;

use App\Support\RearrangeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RearrangeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('rearrange_recommendations');
    }

    public function test_returns_recommendations_with_positive_moves(): void
    {
        $recs = RearrangeCalculator::recommendations();

        $this->assertGreaterThan(0, $recs->count());
        $this->assertTrue($recs->every(fn ($r) => $r['total_move'] > 0));
    }

    public function test_excludes_web_stores(): void
    {
        $recs = RearrangeCalculator::recommendations();

        $this->assertTrue($recs->every(fn ($r) => ! str_contains($r['donors'], 'WEB')
            && ! str_contains($r['donors'], 'web')
            && ! str_contains($r['receivers'], 'WEB')
            && ! str_contains($r['receivers'], 'web')));
    }

    public function test_sorted_by_total_move_descending(): void
    {
        $recs = RearrangeCalculator::recommendations();
        $moves = $recs->pluck('total_move')->all();

        $sorted = $moves;
        rsort($sorted);
        $this->assertSame($sorted, $moves);
    }

    public function test_result_is_cached(): void
    {
        RearrangeCalculator::recommendations();
        $this->assertTrue(Cache::has('rearrange_recommendations'));
    }
}
