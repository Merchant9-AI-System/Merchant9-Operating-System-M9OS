<?php

namespace Tests\Feature;

use App\Support\BranchFocusCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BranchFocusCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('branch_focus');
    }

    public function test_returns_rows_with_valid_focus_areas(): void
    {
        $focus = BranchFocusCalculator::focus();
        $this->assertGreaterThan(0, $focus->count());

        $valid = ['Data Tak Cukup', 'Understock - Fokus Beli', 'Overstock - Fokus Jual/Promosi', 'Seimbang'];
        $this->assertTrue($focus->every(fn ($f) => in_array($f['focus_area'], $valid, true)));
    }

    public function test_excludes_online_stores(): void
    {
        $focus = BranchFocusCalculator::focus();
        $this->assertTrue($focus->every(fn ($f) => ! in_array($f['store_code'], ['WEB', 'web'], true)));
    }

    public function test_sorted_by_absolute_gap_descending(): void
    {
        $focus = BranchFocusCalculator::focus();
        $gaps = $focus->map(fn ($f) => abs($f['gap']))->all();
        $sorted = $gaps;
        rsort($sorted);
        $this->assertSame($sorted, $gaps);
    }
}
