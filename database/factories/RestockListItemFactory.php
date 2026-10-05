<?php

namespace Database\Factories;

use App\Models\RestockListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockListItem>
 */
class RestockListItemFactory extends Factory
{
    public function definition(): array
    {
        $suggested = fake()->numberBetween(1, 12);

        return [
            'internal_code' => strtoupper(fake()->unique()->bothify('CF??##???#?')),
            'item_desc' => 'CINCIN EMAS 916',
            'category_name' => 'CINCIN EMAS',
            'qty_to_order' => $suggested,
            'suggested_qty' => $suggested,
            'status' => RestockListItem::STATUS_DRAFT,
            'created_by' => fake()->name(),
        ];
    }

    public function ordered(): static
    {
        return $this->state(fn () => ['status' => RestockListItem::STATUS_ORDERED, 'ordered_at' => now()]);
    }
}
