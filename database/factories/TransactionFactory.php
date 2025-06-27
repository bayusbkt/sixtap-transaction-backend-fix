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
        $type = $this->faker->randomElement(['pembelian', 'top up', 'refund', 'pencairan']);

        $status = $type === 'pencairan'
            ? $this->faker->randomElement(['berhasil', 'menunggu', 'gagal'])
            : $this->faker->randomElement(['berhasil', 'gagal']);

        return [
            'user_id'       => User::factory(),
            'rfid_card_id'  => RfidCard::factory(),
            'canteen_id'    => Canteen::factory(),
            'type'          => $type,
            'status'        => $status,
            'amount'        => $this->faker->randomFloat(2, 1000, 50000),
            'note'          => $this->faker->optional()->sentence(),
        ];
    }
}
