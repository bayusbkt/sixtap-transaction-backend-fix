<?php

namespace Database\Seeders;

use App\Models\RfidCard;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Canteen;
use App\Models\Transaction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->count(50)->create();

        $students = User::factory()->count(20)->create([
            'role_id' => 2,
        ]);

        foreach ($students as $student) {
            $rfid = RfidCard::factory()->create([
                'user_id' => $student->id,
            ]);

            Wallet::factory()->create([
                'user_id' => $student->id,
                'rfid_card_id' => $rfid->id,
            ]);
        }

        $canteenAdmins = User::factory()->count(3)->create([
            'role_id' => 4,
        ]);

        foreach ($canteenAdmins as $admin) {
            Canteen::factory()->create([
                'opened_by' => $admin->id,
            ]);
        }

        Transaction::factory()->count(30)->create();
    }
}
