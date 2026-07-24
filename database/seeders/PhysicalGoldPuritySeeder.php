<?php

namespace Database\Seeders;

use App\Models\PhysicalGoldPurity;
use Illuminate\Database\Seeder;

class PhysicalGoldPuritySeeder extends Seeder
{
    /**
     * Kod & faktor tulen disahkan drpd Weekly Stock Report sebenar (Pure Weight ÷ Weight per baris) -
     * JANGAN tambah/ubah tanpa arahan eksplisit, ini nilai perniagaan sebenar, bukan anggaran millesimal.
     */
    public function run(): void
    {
        $purities = [
            ['code' => '9999', 'factor' => 1.0000, 'sort_order' => 1, 'is_base_purity' => true],
            ['code' => '999', 'factor' => 0.9900, 'sort_order' => 2, 'is_base_purity' => true],
            ['code' => '950', 'factor' => 0.9400, 'sort_order' => 3, 'is_base_purity' => true],
            ['code' => '916', 'factor' => 0.9100, 'sort_order' => 4, 'is_base_purity' => true],
            ['code' => '835', 'factor' => 0.8000, 'sort_order' => 5, 'is_base_purity' => true],
            ['code' => '750', 'factor' => 0.7000, 'sort_order' => 6, 'is_base_purity' => true],
            ['code' => '585', 'factor' => 0.5500, 'sort_order' => 7, 'is_base_purity' => true],
            ['code' => '375', 'factor' => 0.3500, 'sort_order' => 8, 'is_base_purity' => true],
            // Faktor "blended" tetap - dipakai automatik (bukan pilihan pengguna) utk kategori
            // yg tiada pecahan ketulenan per-item dlm laporan sebenar (Stock at Branch, Stock at
            // HQ, New Stock Not Yet Key-in, Outstanding Gold Due to Suppliers).
            ['code' => '930', 'factor' => 0.9300, 'sort_order' => 9, 'is_base_purity' => false],
            // Varian 916 - sub-akaun/tag istimewa (rujuk Weekly Stock Report sebenar), faktor
            // sama dgn 916 asas tapi kod berasingan supaya dipilih terus via Select (bukan set
            // tetap lalai - user tambah manual bila perlu via "+ Tambah Baris Lain").
            ['code' => '916 - YS', 'factor' => 0.9100, 'sort_order' => 10, 'is_base_purity' => false],
            ['code' => '916 - KIV', 'factor' => 0.9100, 'sort_order' => 11, 'is_base_purity' => false],
        ];

        foreach ($purities as $purity) {
            PhysicalGoldPurity::updateOrCreate(['code' => $purity['code']], $purity);
        }
    }
}
