<?php

namespace App\Services;

use App\Repositories\WalletRepository;
use App\Helpers\HandleServiceResponse;
use Exception;
use Illuminate\Support\Facades\Hash;

class WalletService
{
    protected $walletRepository;

    public function __construct(WalletRepository $walletRepository)
    {
        $this->walletRepository = $walletRepository;
    }

    public function getBalance(int $userId): array
    {
        $wallet = $this->walletRepository->findWalletByUserId($userId);

        if (!$wallet) {
            return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
        }

        return HandleServiceResponse::successResponse('Cek saldo berhasil.', [
            'name' => $wallet->user->name,
            'card_uid' => $wallet->rfidCard->card_uid,
            'balance' => $wallet->balance,
            'last_top_up' => $wallet->last_top_up,
        ]);
    }

    public function addPin(int $userId, string $pin): array
    {
        try {
            $user = $this->walletRepository->findUser($userId);

            if (!$user) {
                return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
            }

            if (!empty($user->pin)) {
                return HandleServiceResponse::errorResponse('PIN sudah diatur sebelumnya.', 422);
            }

            $success = $this->walletRepository->updateUserPin($userId, Hash::make($pin));

            if (!$success) {
                return HandleServiceResponse::errorResponse('Gagal menambahkan PIN.', 500);
            }

            return HandleServiceResponse::successResponse('PIN berhasil ditambahkan.');
        } catch (Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat menambahkan PIN.', 500);
        }
    }

    public function updatePin(int $userId, string $oldPin, string $pin): array
    {
        try {
            $user = $this->walletRepository->findUser($userId);

            if (!$user) {
                return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
            }

            if (empty($user->pin)) {
                return HandleServiceResponse::errorResponse('PIN lama belum diatur. Silakan atur PIN terlebih dahulu.', 400);
            }

            if (!Hash::check($oldPin, $user->pin)) {
                return HandleServiceResponse::errorResponse('PIN lama tidak sesuai.', 401);
            }

            if (Hash::check($pin, $user->pin)) {
                return HandleServiceResponse::errorResponse('PIN baru tidak boleh sama dengan PIN lama.', 422);
            }

            $success = $this->walletRepository->updateUserPin($userId, Hash::make($pin));

            if (!$success) {
                return HandleServiceResponse::errorResponse('Gagal memperbarui PIN.', 500);
            }

            return HandleServiceResponse::successResponse('PIN berhasil diperbarui.');
        } catch (Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat update PIN.', 500);
        }
    }
}