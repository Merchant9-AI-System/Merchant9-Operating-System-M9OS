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
    ],

    // Threshold % beza (Physical Net Pure Gold vs Book Net Weight) utk status Gold Reconciliation -
    // rujuk App\Support\PhysicalGoldReconciliationCalculator.
    'physical_gold_balance' => [
        'reconciliation_yellow_pct' => (float) env('PGB_RECON_YELLOW_PCT', 2.0),
        'reconciliation_red_pct' => (float) env('PGB_RECON_RED_PCT', 5.0),
    ],
];
