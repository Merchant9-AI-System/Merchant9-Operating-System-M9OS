<?php

/**
 * Tier "Free Gift - Customer": hadiah ikut JUMLAH belian satu customer (VIPNo) dalam tempoh yang dipilih.
 * SUMBER TUNGGAL - dipakai oleh tool MCP get-gift-tier-summary (App\Support\GiftTierAnalytics); ubah di sini
 * sahaja kalau chart hadiah berubah.
 *
 * - min     : belian minimum (RM) untuk tier ini; tier terakhir yang dilepasi menang.
 * - label   : nama tier (sama seperti laporan Excel Free Gift).
 * - items   : barang hadiah (1 unit setiap customer dalam tier) - utk anggaran unit Procurement.
 * - choice  : customer PILIH SALAH SATU - tak boleh dikira tepat, jadi dilaporkan berasingan.
 */
return [
    'tiers' => [
        ['min' => 0, 'label' => 'Tiada hadiah (bawah RM1,000)', 'items' => []],
        ['min' => 1000, 'label' => 'Pouch Bag Small', 'items' => ['Pouch Bag Small']],
        ['min' => 2000, 'label' => 'Pouch Bag Medium', 'items' => ['Pouch Bag Medium']],
        ['min' => 3000, 'label' => 'Umbrella', 'items' => ['Umbrella']],
        ['min' => 5000, 'label' => 'Tumbler', 'items' => ['Tumbler']],
        ['min' => 7000, 'label' => 'Tote Bag Small / Jewelry Box', 'items' => [], 'choice' => ['Tote Bag Small', 'Jewelry Box']],
        ['min' => 13000, 'label' => 'Tote Bag Large', 'items' => ['Tote Bag Large']],
        ['min' => 19000, 'label' => 'Tote Bag Large + Umbrella', 'items' => ['Tote Bag Large', 'Umbrella']],
        ['min' => 25000, 'label' => 'Tote Bag Large + Jewelry Box', 'items' => ['Tote Bag Large', 'Jewelry Box']],
    ],
];
