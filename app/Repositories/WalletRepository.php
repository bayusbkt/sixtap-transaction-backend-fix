<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Wallet;

class WalletRepository
{
    public function findWalletByUserId(int $userId)
    {
        return Wallet::where('user_id', $userId)->first();
    }

    public function findUser(int $userId)
    {
        return User::find($userId);
    }

    public function updateUserPin(int $userId, string $hashedPin)
    {
        $user = $this->findUser($userId);
        
        if (!$user) {
            return false;
        }

        return $user->update(['pin' => $hashedPin]);
    }

    public function createWallet(array $data): Wallet
    {
        return Wallet::create($data);
    }

    public function updateWalletBalance(int $userId, float $amount)
    {
        $wallet = $this->findWalletByUserId($userId);
        
        if (!$wallet) {
            return false;
        }

        return $wallet->update(['balance' => $amount]);
    }
}