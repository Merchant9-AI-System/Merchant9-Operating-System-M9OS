<?php

use App\Mcp\Tools\GetDormantMembersTool;
use App\Mcp\Tools\GetGiftTierSummaryTool;
use App\Mcp\Tools\GetGoldRateTrendTool;
use App\Mcp\Tools\GetGrossMarginTool;
use App\Mcp\Tools\GetMemberActivityTool;
use App\Mcp\Tools\GetSalesSummaryTool;
use App\Mcp\Tools\GetTopProductsTool;
use App\Mcp\Tools\GetVendorSpendTool;

/**
 * Skop tool DATA setiap peranan agent AI (rujuk AI_AGENTS_PLAN.md, bahagian "Peranan agent"). SUMBER TUNGGAL -
 * ubah di sini sahaja bila skop berubah; dibaca oleh App\Mcp\Servers\RoleInsightsServer.
 *
 * Setiap server peranan SENTIASA turut menyediakan (dikunci kpd peranannya, bukan dlm senarai di bawah):
 *  - write-insight       : hanya boleh menulis insight atas nama peranan itu sendiri.
 *  - get-previous-insights: hanya insight peranan itu (CEO sahaja boleh baca semua).
 * Server penuh `insights` (semua tool, semua peranan) kekal utk kegunaan admin/pembangun.
 *
 * Kunci = nilai enum App\Enums\InsightAgent. Media tertangguh (tiada sumber data) - tiada server.
 */
return [
    'procurement' => [
        // Perancangan belian: jualan (guna metric=pieces), design terlaris, perbelanjaan pembekal, unit hadiah, harga emas.
        GetSalesSummaryTool::class,
        GetTopProductsTool::class,
        GetVendorSpendTool::class,
        GetGiftTierSummaryTool::class,
        GetGoldRateTrendTool::class,
    ],

    'finance' => [
        // Kewangan: jualan, margin kasar, perbelanjaan pembekal, harga emas (tool AP/GL ditambah selepas keputusan Finance).
        GetSalesSummaryTool::class,
        GetGrossMarginTool::class,
        GetVendorSpendTool::class,
        GetGoldRateTrendTool::class,
    ],

    'marketing' => [
        // Pemasaran: jualan, design terlaris, hadiah customer, aktiviti & ahli dormant.
        GetSalesSummaryTool::class,
        GetTopProductsTool::class,
        GetGiftTierSummaryTool::class,
        GetMemberActivityTool::class,
        GetDormantMembersTool::class,
    ],

    // CEO TIDAK query pangkalan data - hanya baca insight semua peranan & tulis insight sendiri (rujuk pelan).
    'ceo' => [],
];
