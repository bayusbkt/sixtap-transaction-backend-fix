<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Canteen>
 */
class CanteenFactory extends Factory
{
    public function definition(): array
    {
        $openedAt = $this->faker->dateTimeBetween('-1 week', 'now');
        $closedAt = $this->faker->boolean(70) ? $this->faker->dateTimeBetween($openedAt, 'now') : null;

        return [
            'initial_balance'  => $this->faker->randomFloat(2, 100000, 500000),
            'current_balance'  => $this->faker->randomFloat(2, 100000, 1000000),
            'is_settled'       => $this->faker->boolean(),
            'settlement_time'  => $this->faker->optional()->dateTimeBetween($closedAt ?? $openedAt, 'now'),
            'opened_by'        => User::factory(),
            'opened_at'        => $openedAt,
            'closed_at'        => $closedAt,
            'note'             => $this->faker->optional()->sentence(),
        ];
    }
}
