<?php

namespace Database\Factories;

use App\Enums\MonitorType;
use App\Enums\MonitorVisibility;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->domainWord(),
            'type' => MonitorType::Http,
            'target' => 'https://'.fake()->domainName(),
            'port' => null,
            'expected_statuses' => [200],
            'expected_body_contains' => null,
            'timeout_seconds' => 10,
            'max_response_time_ms' => null,
            'interval_minutes' => 1,
            'is_active' => true,
            'visibility' => MonitorVisibility::Private,
            'is_up' => null,
            'next_check_at' => null,
        ];
    }

    public function tcp(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Tcp,
            'target' => '127.0.0.1',
            'port' => 22,
        ]);
    }
}
