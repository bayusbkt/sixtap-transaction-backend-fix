<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfidCard>
 */
class RfidCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::inRandomOrder()->where('role_id', 2)->first();
        
        return [
            'user_id' => $user?->id,
            'card_uid' => strtoupper(Str::random(10)),
            'is_active' => true,
            'activated_at' => now(),
            'blocked_at' => null,
        ];
    }
}
