<?php

namespace Database\Factories;

use App\Models\HqDirection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HqDirection> */
class HqDirectionFactory extends Factory
{
    protected $model = HqDirection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => '<p>'.fake()->sentence().'</p>',
            'effective_from' => HqDirection::today()->toDateString(),
            'effective_to' => null,
            'all_branches' => true,
            'created_by_id' => null,
            'created_by_name' => 'HQ',
        ];
    }

    public function forBranches(): static
    {
        return $this->state(['all_branches' => false]);
    }

    public function endsOn(string $date): static
    {
        return $this->state(['effective_to' => $date]);
    }
}
