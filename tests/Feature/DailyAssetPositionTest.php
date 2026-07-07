<?php

namespace Tests\Feature;

use App\Models\DailyAssetPosition;
use App\Models\DailyAssetPositionAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssetPositionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEntry(array $overrides = []): DailyAssetPosition
    {
        return DailyAssetPosition::create(array_merge([
            'entry_date' => '2026-07-01',
            'opening_stock_weight' => 1000,
            'new_stock' => 100,
            'used_gold' => 10,
            'gold_bar' => 5,
            'unreceived_bar' => 0,
            'loan_received' => 0,
            'sales' => 80,
            'payment_to_supplier' => 0,
            'stock_out_return' => 0,
            'loss_from_melting' => 2,
            'loan_out' => 0,
            'closing_stock' => 1033, // = 1000 + (100+10+5+0+0) - (80+0+0+2+0) = 1033
            'supplier_hutang' => 20,
            'supplier_overpaid' => 5,
            'ambank_balance' => 10000,
            'affin_balance' => 5000,
            'cash' => 2000,
            'affin_rm' => 1000,
            'od_affin' => 500,
            'locked_gold_bar' => 0,
            'created_by' => 'Accountant Test',
        ], $overrides));
    }

    public function test_total_stock_in_out_are_auto_calculated_on_save(): void
    {
        $entry = $this->makeEntry();

        $this->assertEquals(115.0, (float) $entry->total_stock_in);
        $this->assertEquals(82.0, (float) $entry->total_stock_out);
    }

    public function test_net_weight_is_auto_calculated_from_keyed_closing_stock(): void
    {
        $entry = $this->makeEntry();

        // 1033 - 20 + 5 = 1018
        $this->assertEquals(1018.0, (float) $entry->net_weight);
    }

    public function test_available_cash_is_auto_calculated(): void
    {
        $entry = $this->makeEntry();

        // 10000 + 5000 + 2000 + 1000 - 500 = 17500
        $this->assertEquals(17500.0, (float) $entry->available_cash);
    }

    public function test_computed_totals_ignore_tampered_manual_input(): void
    {
        // Walaupun total_stock_in/out/net_weight/available_cash dihantar terus dgn nilai salah,
        // booted() saving() WAJIB timpa dgn nilai formula sebenar - tak boleh dipercayai drpd input.
        $entry = $this->makeEntry([
            'total_stock_in' => 999999,
            'total_stock_out' => 999999,
            'net_weight' => 999999,
            'available_cash' => 999999,
        ]);

        $this->assertEquals(115.0, (float) $entry->total_stock_in);
        $this->assertEquals(82.0, (float) $entry->total_stock_out);
        $this->assertEquals(1018.0, (float) $entry->net_weight);
        $this->assertEquals(17500.0, (float) $entry->available_cash);
    }

    public function test_entry_date_must_be_unique(): void
    {
        $this->makeEntry(['entry_date' => '2026-07-01']);

        $this->expectException(QueryException::class);
        $this->makeEntry(['entry_date' => '2026-07-01']);
    }

    public function test_opening_stock_mismatch_detected_against_previous_closing(): void
    {
        $this->makeEntry(['entry_date' => '2026-07-01', 'closing_stock' => 1033]);

        $next = $this->makeEntry([
            'entry_date' => '2026-07-02',
            'opening_stock_weight' => 999, // patut 1033 drpd rekod sebelum
            'closing_stock' => 999,
        ]);

        $this->assertTrue($next->hasOpeningStockMismatch());
    }

    public function test_no_opening_stock_mismatch_when_matches_previous_closing(): void
    {
        $this->makeEntry(['entry_date' => '2026-07-01', 'closing_stock' => 1033]);

        $next = $this->makeEntry([
            'entry_date' => '2026-07-02',
            'opening_stock_weight' => 1033,
            'closing_stock' => 1033,
        ]);

        $this->assertFalse($next->hasOpeningStockMismatch());
    }

    public function test_closing_stock_mismatch_detected_when_keyed_value_differs_from_formula(): void
    {
        $entry = $this->makeEntry(['closing_stock' => 5000]); // sepatutnya 1033 ikut formula

        $this->assertTrue($entry->hasClosingStockMismatch());
    }

    public function test_closing_stock_before_returns_null_when_no_records(): void
    {
        $this->assertNull(DailyAssetPosition::closingStockBefore(null));
        $this->assertNull(DailyAssetPosition::closingStockBefore('2026-07-01'));
    }

    public function test_audit_trail_created_on_insert(): void
    {
        $entry = $this->makeEntry();

        $this->assertSame(1, $entry->audits()->count());
        $this->assertSame('created', $entry->audits()->first()->action);
        $this->assertSame('Accountant Test', $entry->audits()->first()->actor);
    }

    public function test_audit_trail_records_changes_on_update(): void
    {
        $entry = $this->makeEntry();
        $entry->update(['sales' => 200, 'updated_by' => 'Accountant Two']);

        $audit = $entry->audits()->where('action', 'updated')->first();

        $this->assertNotNull($audit);
        $this->assertArrayHasKey('sales', $audit->changes);
        $this->assertEquals(80, (float) $audit->changes['sales']['old']);
    }

    public function test_deleting_entry_cascades_audit_records(): void
    {
        $entry = $this->makeEntry();
        $id = $entry->id;
        $entry->delete();

        $this->assertSame(0, DailyAssetPositionAudit::where('daily_asset_position_id', $id)->count());
    }
}
