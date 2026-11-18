<?php

namespace Database\Factories;

use App\Models\HomeRoleModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeRoleModule>
 */
class HomeRoleModuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'role_name' => 'leader_branch',
            'module_key' => 'checklist',
            'enabled' => true,
            'sort_order' => 0,
            'scope_mode' => 'own',
            'scope_stores' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
