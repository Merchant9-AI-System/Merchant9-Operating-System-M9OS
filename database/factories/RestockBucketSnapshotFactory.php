<?php

namespace Database\Factories;

use App\Models\RestockBucketSnapshot;
use App\Support\RestockAnalysisCalculator;
use App\Support\RestockSnapshotQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockBucketSnapshot>
 */
class RestockBucketSnapshotFactory extends Factory
{
    protected $model = RestockBucketSnapshot::class;

    /** `urgency` sentiasa selari dgn Cadangan akhir (termasuk yg ditimpa semasa create()). */
    public function configure(): static
    {
        return $this->afterMaking(fn (RestockBucketSnapshot $snapshot) => $snapshot->urgency = RestockSnapshotQuery::urgencyOf((string) $snapshot->verdict));
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => RestockBucketSnapshot::KIND_SIZE,
            'category_code' => 'RT',
            'store_code' => 'DAMAI',
            'bucket' => (string) fake()->unique()->numberBetween(1000, 999999),
            'stock' => 2,
            'sold_1w' => 1,
            'sold_1m' => 3,
            'sold_3m' => 6,
            'sold_6m' => 10,
            'sold_1y' => 20,
            'sold_all' => 30,
            'received_1w' => 0,
            'received_1m' => 2,
            'received_3m' => 8,
            'received_6m' => 14,
            'received_1y' => 26,
            'received_all' => 40,
            'velocity_per_month' => 2.0,
            'target_stock' => 3,
            'gap' => 1,
            'cover_months' => 1.0,
            'verdict' => RestockAnalysisCalculator::VERDICT_RESTOCK,
            'computed_at' => now(),
        ];
    }
}
