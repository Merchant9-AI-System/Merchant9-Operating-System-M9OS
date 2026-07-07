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
    ],
];
