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
            ['code' => '9999', 'factor' => 1.0000, 'sort_order' => 1],
            ['code' => '999', 'factor' => 0.9900, 'sort_order' => 2],
            ['code' => '950', 'factor' => 0.9400, 'sort_order' => 3],
            ['code' => '916', 'factor' => 0.9100, 'sort_order' => 4],
            ['code' => '835', 'factor' => 0.8000, 'sort_order' => 5],
            ['code' => '750', 'factor' => 0.7000, 'sort_order' => 6],
            ['code' => '585', 'factor' => 0.5500, 'sort_order' => 7],
            ['code' => '375', 'factor' => 0.3500, 'sort_order' => 8],
        ];

        foreach ($purities as $purity) {
            PhysicalGoldPurity::updateOrCreate(['code' => $purity['code']], $purity);
        }
    }
}
