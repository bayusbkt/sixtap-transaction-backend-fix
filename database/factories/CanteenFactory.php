<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Canteen>
 */
class CanteenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $opener = User::where('role_id', 4)->inRandomOrder()->first();

        if (!$opener) {
            $opener = User::factory()->create(['role_id' => 4]);
        }

        $openedAt = $this->faker->dateTimeBetween('-1 week', 'now');
        $closedAt = $this->faker->boolean(70) ? $this->faker->dateTimeBetween($openedAt, 'now') : null;

        return [
            'initial_balance'  => $this->faker->randomFloat(2, 100000, 500000),
            'current_balance'  => $this->faker->randomFloat(2, 100000, 1000000),
            'is_settled'       => $this->faker->boolean(),
            'settlement_time'  => $this->faker->optional()->dateTimeBetween($closedAt ?? $openedAt, 'now'),
            'opened_by'        => $opener->id,
            'opened_at'        => $openedAt,
            'closed_at'        => $closedAt,
            'note'             => $this->faker->optional()->sentence(),
        ];
    }
}
