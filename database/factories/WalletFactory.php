<?php

namespace Database\Factories;

use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::inRandomOrder()->where('role_id', 2)->first();

        $rfidCard = RfidCard::where('user_id', $user?->id)->inRandomOrder()->first();

        return [
            'user_id' => $user?->id,
            'rfid_card_id' => $rfidCard?->id,
            'last_top_up' => now()->subDays(rand(1, 30)),
            'balance' => rand(500, 200000),
        ];
    }
}
