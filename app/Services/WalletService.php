<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\Hash;

class WalletService
{
    public function getBalance(int $userId): array {
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
                'card_uid' => $wallet->rfidCard->card_uid,
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
                'code' => 500
            ];
        }
    }

    public function updatePin(int $userId, string $oldPin, string $pin): array
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

            if (empty($user->pin)) {
                return [
                    'status' => 'error',
                    'message' => 'PIN lama belum diatur. Silakan atur PIN terlebih dahulu.',
                    'code' => 400
                ];
            }

            if (!Hash::check($oldPin, $user->pin)) {
                return [
                    'status' => 'error',
                    'message' => 'PIN lama tidak sesuai.',
                    'code' => 401
                ];
            }

            if (Hash::check($pin, $user->pin)) {
                return [
                    'status' => 'error',
                    'message' => 'PIN baru tidak boleh sama dengan PIN lama.',
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
                'code' => 500
            ];
        }
    }
}
