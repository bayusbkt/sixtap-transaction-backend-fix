<?php

namespace Database\Seeders;

use App\Models\RfidCard;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Canteen;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $bayu = User::create([
            'name' => 'Bayu Subekti',
            'email' => 'bayusubekti@email.com',
            'password' => Hash::make('bayu123'),
            'phone' => '081234567890',
            'pin' => Hash::make('123456'),
            'role_id' => 2,
            'batch' => "2025",
            'schoolclass_id' => 2,
        ]);

        $bayuRfid = RfidCard::create([
            'user_id' => $bayu->id,
            'card_uid' => 'ABC123EFG',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        Wallet::create([
            'user_id' => $bayu->id,
            'rfid_card_id' => $bayuRfid->id,
            'last_top_up' => now(),
            'balance' => 100000,
        ]);


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
