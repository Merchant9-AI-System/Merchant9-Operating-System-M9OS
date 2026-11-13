<?php

// Feature flags utk CEO Dashboard Phase 1 - toggle via .env, tiada perubahan kod diperlukan
// utk enable/disable. Semua default true (ciri baru aktif secara lalai selepas deploy).
return [
    'ceo_features' => [
        'action_centre' => env('CEO_ACTION_CENTRE_ENABLED', true),
        'branch_health' => env('CEO_BRANCH_HEALTH_ENABLED', true),
        'capital_trend' => env('CEO_CAPITAL_TREND_ENABLED', true),
        'lost_opportunity' => env('CEO_LOST_OPPORTUNITY_ENABLED', true),
        'rearrangement' => env('CEO_REARRANGEMENT_ENABLED', true),
        'daily_asset_position' => env('CEO_DAILY_ASSET_POSITION_ENABLED', true),
        'physical_gold_balance' => env('CEO_PHYSICAL_GOLD_BALANCE_ENABLED', true),
    ],

    // Threshold % beza (accountant vs JEMiSys) utk status reconciliation Daily Asset Position -
    // permulaan konservatif, tukar bila-bila tanpa perubahan kod (rujuk DailyAssetPositionCalculator).
    'daily_asset_position' => [
        'reconciliation_yellow_pct' => (float) env('DAP_RECON_YELLOW_PCT', 2.0),
        'reconciliation_red_pct' => (float) env('DAP_RECON_RED_PCT', 5.0),

        // Harga emas TETAP (RM per gram, emas tulen 999) utk nilaikan Net Weight/Hutang Supplier dlm RM
        // pada graf "Total Asset Value" - SENGAJA malar supaya jumlah hanya bergerak bila stok/cash
        // berubah, bukan sebab harga pasaran. Nilai awal = median 12 bulan kadar beli kelas 999
        // (GoldRateByHolderHist, penutup bulanan Nov 2025 - Okt 2026, dikira 5 Nov 2026; purata RM540.17,
        // julat RM480-632). Semak semula & tukar ikut keputusan pengurusan (cth. awal setiap suku tahun).
        'gold_reference_price_per_gram' => (float) env('DAP_GOLD_REFERENCE_PRICE', 538.50),
    ],

    // Threshold % beza (Physical Net Pure Gold vs Book Net Weight) utk status Gold Reconciliation -
    // rujuk App\Support\PhysicalGoldReconciliationCalculator.
    'physical_gold_balance' => [
        'reconciliation_yellow_pct' => (float) env('PGB_RECON_YELLOW_PCT', 2.0),
        'reconciliation_red_pct' => (float) env('PGB_RECON_RED_PCT', 5.0),
    ],

    // Halaman /home (ringkasan semalam): kunci kad yang jadi kad HERO (paling besar) pada halaman CEO. Pilihan:
    // sales | sales_value | hedge | asset_value | available_cash | cash_for_gb | lock_ace | hutang_used_gold |
    // physical_vs_book | used_gold. Boleh ditukar bila boss sudah memutuskan, tanpa ubah kod.
    'home' => [
        'ceo_hero_key' => env('HOME_CEO_HERO_KEY', ''),
    ],

    // Formula hedge harian Finance: (berat jualan x sales_purity) - (berat buyback x buyback_purity)
    // = gram tulen bersih. Faktor ialah andaian tetap Finance (mesej harian "SALES - BUYBACK"), bukan
    // purata sebenar - rujuk App\Support\DailyHedgeFigure. buyback_doc_types = DocType TblTradeIn.
    'hedge' => [
        'sales_purity' => (float) env('HEDGE_SALES_PURITY', 0.935),
        'buyback_purity' => (float) env('HEDGE_BUYBACK_PURITY', 0.900),
        'buyback_doc_types' => ['TI', 'TC'],
    ],
];
