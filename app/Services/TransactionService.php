<?php

namespace App\Services;

use App\Helpers\LogFailedTransaction;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function handleTopUp(string $cardUid, int $amount): array
    {
        $card = RfidCard::where('card_uid', $cardUid)->first();

        if (!$card) {
            return [
                'status' => 'error',
                'message' => 'Kartu tidak ditemukan.',
                'code' => 404
            ];
        }

        if ($card->is_active == false) {
            return [
                'status' => 'error',
                'message' => 'Kartu tidak aktif.',
                'code' => 422
            ];
        }


        $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

        if (!$wallet) {
            return [
                'status' => 'error',
                'message' => 'Wallet pengguna tidak ditemukan.',
                'code' => 404
            ];
        }

        try {
            DB::beginTransaction();

            $wallet->update([
                'balance' => $wallet->balance + $amount,
                'last_top_up' => now()
            ]);

            Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'berhasil',
                'amount' => $amount
            ]);

            $dataCard = $card->load('user');

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Top up berhasil.',
                'code' => 200,
                'data' => $dataCard,
            ];
        } catch (\Exception $e) {
            DB::rollback();

            LogFailedTransaction::format(
                $card->user_id,
                $card->id,
                null,
                $amount,
                'top up',
                'Kesalahan pada server.'
            );

            return [
                'status' => 'error',
                'message' => 'Top up gagal.',
                'code' => 500,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getTopUpHistory(int $perPage = 50): array
    {
        $topUpHistory = Transaction::where('type', 'top up')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        if ($topUpHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat top up.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat top up berhasil didapatkan.',
            'code' => 200,
            'data' => $topUpHistory
        ];
    }

    public function startTransaction(string $cardUid, int $amount, int $canteenId): array
    {
        $card = RfidCard::where('card_uid', $cardUid)->first();

        if (!$card) {
            return [
                'status' => 'error',
                'message' => 'Kartu tidak ditemukan.',
                'code' => 404
            ];
        }

        if ($card->is_active == false) {
            return [
                'status' => 'error',
                'message' => 'Kartu tidak aktif.',
                'code' => 422
            ];
        }

        $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

        if (!$wallet) {
            DB::rollBack();
            return [
                'status' => 'error',
                'message' => 'Wallet pengguna tidak ditemukan.',
                'code' => 404
            ];
        }

        $canteenExists = Canteen::where('id', $canteenId)->exists();
        if (!$canteenExists) {
            return [
                'status' => 'error',
                'message' => 'Kantin tidak ditemukan.',
                'code' => 404
            ];
        }

        try {
            DB::beginTransaction();

            if ($wallet->balance < $amount) {
                DB::rollBack();

                LogFailedTransaction::format(
                    $card->user_id,
                    $card->id,
                    $canteenId,
                    $amount,
                    'pembelian',
                    'Saldo tidak mencukupi.'
                );

                return [
                    'status' => 'error',
                    'message' => 'Saldo tidak mencukupi.',
                    'code' => 422
                ];
            }

            $wallet->update([
                'balance' => $wallet->balance - $amount
            ]);

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => $canteenId,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Transaksi pembelian berhasil dilakukan.',
                'code' => 200,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'user' => $card->user->only(['id', 'name']),
                    'amount' => $amount,
                    'canteen_id' => $canteenId
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            LogFailedTransaction::format(
                $card->user_id,
                $card->id,
                $canteenId,
                $amount,
                'pembelian',
                'Kesalahan pada server.'
            );

            return [
                'status' => 'error',
                'message' => 'Transaksi pembelian gagal.',
                'code' => 500,
                'error' => $e->getMessage()
            ];
        }
    }
}
