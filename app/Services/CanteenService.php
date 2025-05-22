<?php

namespace App\Services;

use App\Models\Canteen;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CanteenService
{
    public function inputInitialFund(int $userId, int $amount): array
    {
        $user = User::find($userId);
            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'Pengguna tidak ditemukan.',
                    'code' => 404,
                ];
            }

        try {
            DB::beginTransaction();

            $canteen = Canteen::create([
                'initial_balance' => $amount,
                'current_balance' => 0,
                'is_settled' => false,
                'opened_by' => $userId,
                'opened_at' => now(),
                'closed_at' => null,
                'note' => null
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Saldo awal kantin berhasil disimpan.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,
                    'initial_balance' => $canteen->initial_balance,
                    'opened_at' => $canteen->opened_at,
                    'opened_by' => $user->name
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menyimpan saldo awal kantin.',
                'code' => 500,
            ];
        }
    }
}