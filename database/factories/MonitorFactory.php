<?php

namespace Database\Factories;

use App\Enums\DnsRecordType;
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
            'http_method' => 'GET',
            'http_headers' => null,
            'http_body' => null,
            'dns_record_type' => null,
            'expected_statuses' => [200],
            'expected_body_contains' => null,
            'timeout_seconds' => 10,
            'max_response_time_ms' => null,
            'interval_minutes' => 1,
            'grace_minutes' => null,
            'is_active' => true,
            'visibility' => MonitorVisibility::Private,
            'is_up' => null,
            'next_check_at' => null,
        ];
    }

    public function dns(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Dns,
            'target' => 'example.test',
            'dns_record_type' => DnsRecordType::A,
        ]);
    }

    public function push(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Push,
            'target' => null,
            'grace_minutes' => 60,
            'last_ping_at' => now(),
        ]);
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
