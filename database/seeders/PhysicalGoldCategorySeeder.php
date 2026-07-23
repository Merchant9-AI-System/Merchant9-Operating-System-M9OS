<?php

namespace Database\Seeders;

use App\Models\PhysicalGoldCategory;
use Illuminate\Database\Seeder;

class PhysicalGoldCategorySeeder extends Seeder
{
    /** Kategori awal disepadankan drpd struktur sebenar Weekly Stock Report Merchant9. */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'USED_GOLD_HQ',
                'name' => 'Used Gold at HQ',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY,
                'requires_branch' => false,
                'requires_supplier' => false,
                'requires_purity' => true,
                'requires_date_range' => false,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'STOCK_BRANCH',
                'name' => 'Stock at Branch',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY,
                'requires_branch' => true,
                'requires_supplier' => false,
                'requires_purity' => true,
                'requires_date_range' => false,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'STOCK_HQ',
                'name' => 'Stock at HQ',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY,
                'requires_branch' => false,
                'requires_supplier' => false,
                'requires_purity' => false,
                'requires_date_range' => false,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 3,
            ],
            [
                'code' => 'NEW_STOCK_SUPPLIER',
                'name' => 'New Stock Not Yet Keyed-In',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY,
                'requires_branch' => false,
                'requires_supplier' => true,
                'requires_purity' => true,
                'requires_date_range' => false,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 4,
            ],
            [
                'code' => 'GDN_PENDING',
                'name' => 'GDN Not Yet Received / Not Weighed',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY,
                'requires_branch' => false,
                'requires_supplier' => false,
                'requires_purity' => true,
                'requires_date_range' => true,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 5,
            ],
            [
                'code' => 'SUPPLIER_OUTSTANDING',
                'name' => 'Outstanding Gold Due to Suppliers',
                'value_mode' => PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE,
                'requires_branch' => false,
                'requires_supplier' => true,
                'requires_purity' => false,
                'requires_date_range' => false,
                'include_in_physical_total' => true,
                'is_deduction' => false,
                'sort_order' => 6,
            ],
        ];

        foreach ($categories as $category) {
            PhysicalGoldCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
