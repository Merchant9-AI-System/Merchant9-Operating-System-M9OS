<?php

namespace Database\Factories;

use App\Models\RestockSnapshot;
use App\Support\RestockAnalysisCalculator;
use App\Support\RestockSnapshotQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockSnapshot>
 */
class RestockSnapshotFactory extends Factory
{
    protected $model = RestockSnapshot::class;

    /** `urgency` sentiasa selari dgn Cadangan akhir (termasuk yg ditimpa semasa create()). */
    public function configure(): static
    {
        return $this->afterMaking(fn (RestockSnapshot $snapshot) => $snapshot->urgency = RestockSnapshotQuery::urgencyOf((string) $snapshot->verdict));
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internal_code' => strtoupper(fake()->unique()->bothify('DS###??')),
            'store_code' => 'DAMAI',
            'category_code' => 'RT',
            'vendor_code' => 'GRJ',
            'description' => 'RANTAI TANGAN',
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
            'last_sold_at' => now()->subDay(),
            'computed_at' => now(),
        ];
    }

    /** Cadangan tertentu (cth. Sold Out). */
    public function verdict(string $verdict): static
    {
        return $this->state(fn () => ['verdict' => $verdict]);
    }
}
