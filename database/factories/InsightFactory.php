<?php

namespace Database\Factories;

use App\Enums\InsightAgent;
use App\Enums\InsightSeverity;
use App\Models\Insight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
class InsightFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent' => InsightAgent::Procurement,
            'severity' => InsightSeverity::Info,
            'title' => fake()->sentence(5),
            'summary' => fake()->sentence(12),
            'figures' => ['jualan_sep' => 23064000],
            'recommendation' => null,
            'sources' => ['get-sales-summary(2026-09-01..2026-09-30)'],
            'data_as_of' => now(),
            'dedupe_key' => null,
        ];
    }

    public function forAgent(InsightAgent $agent): static
    {
        return $this->state(fn () => ['agent' => $agent]);
    }

    public function severity(InsightSeverity $severity): static
    {
        return $this->state(fn () => ['severity' => $severity]);
    }
}
