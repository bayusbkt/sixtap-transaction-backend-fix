<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\Hash;

class WalletService
{
    public function getBalance(int $userId): array
    {
        $wallet = Wallet::where('user_id', $userId)->first();

        if (!$wallet) {
            return [
                'status' => 'error',
                'message' => 'Wallet pengguna tidak ditemukan.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Cek saldo berhasil.',
            'code' => 200,
            'data' => [
                'name' => $wallet->user->name,
                'rfid_card_id' => $wallet->rfidCard->card_uid,
                'balance' => $wallet->balance,
                'last_top_up' => $wallet->last_top_up,
            ]
        ];
    }

    public function addPin(int $userId, string $pin): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'Pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            if (!empty($user->pin)) {
                return [
                    'status' => 'error',
                    'message' => 'PIN sudah diatur sebelumnya.',
                    'code' => 422
                ];
            }

            $user->update([
                'pin' => Hash::make($pin)
            ]);

            return [
                'status' => 'success',
                'message' => 'PIN berhasil ditambahkan.',
                'code' => 200
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menambahkan PIN.',
                'error' => $e->getMessage(),
                'code' => 500
            ];
        }
    }

    public function updatePin(int $userId, string $pin): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'Pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            if (!empty($user->pin) && Hash::check($pin, $user->pin)) {
                return [
                    'status' => 'error',
                    'message' => 'PIN tidak boleh sama dengan PIN sebelumnya.',
                    'code' => 422
                ];
            }

            $user->update([
                'pin' => Hash::make($pin)
            ]);

            return [
                'status' => 'success',
                'message' => 'PIN berhasil diperbarui.',
                'code' => 200
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat update PIN.',
                'error' => $e->getMessage(),
                'code' => 500
            ];
        }
    }
}
