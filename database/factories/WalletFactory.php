<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use App\Models\RfidCard;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rfid_card_id' => RfidCard::factory(),
            'last_top_up' => now()->subDays(rand(1, 30)),
            'balance' => rand(500, 200000),
        ];
    }
}
