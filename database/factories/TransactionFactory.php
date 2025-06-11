<?php

namespace Database\Factories;

use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $user = User::where('role_id', 2)->inRandomOrder()->first();

        if (!$user) {
            $user = User::factory()->create(['role_id' => 2]);
        }

        $rfidCard = $user->rfidCard ?? RfidCard::factory()->for($user)->create();

        $canteen = Canteen::inRandomOrder()->first() ?? Canteen::factory()->create();

        $type = $this->faker->randomElement(['pembelian', 'top up', 'refund', 'pencairan']);

        $status = match ($type) {
            'pencairan' => $this->faker->randomElement(['berhasil', 'menunggu', 'gagal']),
            default     => $this->faker->randomElement(['berhasil', 'gagal']),
        };

        return [
            'user_id'       => $user->id,
            'rfid_card_id'  => $rfidCard->id,
            'canteen_id'    => $canteen->id,
            'type'          => $type,
            'status'        => $status,
            'amount'        => $this->faker->randomFloat(2, 1000, 50000),
            'note'          => $this->faker->optional()->sentence(),
        ];
    }
}
