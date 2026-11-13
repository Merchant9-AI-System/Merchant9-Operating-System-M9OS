<?php

namespace Database\Factories;

use App\Models\HomeRoleSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeRoleSetting>
 */
class HomeRoleSettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'role_name' => fake()->unique()->slug(2),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
