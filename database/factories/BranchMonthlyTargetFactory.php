<?php

namespace Database\Factories;

use App\Models\BranchMonthlyTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchMonthlyTarget> */
class BranchMonthlyTargetFactory extends Factory
{
    protected $model = BranchMonthlyTarget::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'store_code' => 'WM',
            'month' => BranchMonthlyTarget::currentMonth()->toDateString(),
            'target_rm' => 3000000,
            'target_grams' => 15000,
            'remark' => null,
            'source' => BranchMonthlyTarget::SOURCE_HQ,
            'set_by_id' => null,
            'set_by_name' => 'HQ',
        ];
    }
}
