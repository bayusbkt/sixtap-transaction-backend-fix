<?php

namespace App\Services;

use App\Helpers\LogFailedTransaction;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TransactionService
{
    public function handleTopUp(string $cardUid, int $amount, ?string $note): array
    {
        try {
            DB::beginTransaction();

            $card = RfidCard::where('card_uid', $cardUid)->first();

            if (!$card) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak ditemukan.',
                    'code' => 404
                ];
            }

            if (!$card->is_active) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak aktif.',
                    'code' => 422
                ];
            }

            $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

            if (!$wallet) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

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
                'amount' => $amount,
                'note' => $note
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

            $userId = $card->user_id ?? null;
            $cardId = $card->id ?? null;

            LogFailedTransaction::format(
                $userId,
                $cardId,
                null,
                $amount,
                'top up',
                'Kesalahan pada server.'
            );

            return [
                'status' => 'error',
                'message' => 'Top up gagal.',
                'code' => 500,
            ];
        }
    }

    public function getTopUpHistory(?string $range = null, int $perPage): array
    {
        $query = Transaction::where('type', 'top up')
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid'
            ])
            ->orderBy('created_at', 'desc');

        if ($range) {
            $now = now();
            switch ($range) {
                case 'harian':
                    $query->whereDate('created_at', $now->toDateString());
                    break;
                case 'mingguan':
                    $query->whereBetween('created_at', [$now->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'bulanan':
                    $query->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year);
                    break;
            }
        }

        $topUpHistory = $query->paginate($perPage);


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

    private function validateTransaction(string $cardUid, int $amount, int $canteenOpenerId): array
    {
        try {
            $card = RfidCard::where('card_uid', $cardUid)->first();

            if (!$card) {
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak ditemukan.',
                    'code' => 404
                ];
            }

            if (!$card->is_active) {
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak aktif.',
                    'code' => 422
                ];
            }

            $canteen = Canteen::where('opened_by', $canteenOpenerId)
                ->whereNotNull('opened_at')
                ->whereNull('closed_at')
                ->latest()
                ->first();

            if (!$canteen) {
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada kantin yang sedang dibuka oleh pengguna ini.',
                    'code' => 404
                ];
            }

            if ($canteen->opened_at == null || $canteen->opened_at > now()) {
                return [
                    'status' => 'error',
                    'message' => 'Kantin belum dibuka.',
                    'code' => 422
                ];
            }

            $wallet = Wallet::where('user_id', $card->user_id)->first();

            if (!$wallet) {
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            if ($wallet->balance < $amount) {
                return [
                    'status' => 'error',
                    'message' => 'Saldo tidak mencukupi.',
                    'code' => 422
                ];
            }

            return [
                'status' => 'success',
                'message' => 'Validasi berhasil.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,

                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat validasi.',
                'code' => 500,
            ];
        }
    }

    public function processTransaction(string $cardUid, int $amount, int $canteenOpenerId, ?string $pin = null): array
    {
        $validation = $this->validateTransaction($cardUid, $amount, $canteenOpenerId);

        if ($validation['status'] !== 'success') {
            return $validation;
        }

        $canteenId = $validation['data']['canteen_id'];

        try {
            DB::beginTransaction();

            $card = RfidCard::where('card_uid', $cardUid)->with('user')->first();

            $canteen = Canteen::find($canteenId);

            if ($amount > 20000) {
                if (!$pin) {
                    DB::rollback();
                    return [
                        'status' => 'error',
                        'message' => 'PIN diperlukan untuk transaksi di atas Rp 20.000.',
                        'code' => 422
                    ];
                }

                if (!Hash::check($pin, $card->user->pin)) {
                    DB::rollback();

                    LogFailedTransaction::format(
                        $card->user_id,
                        $card->id,
                        $canteen->id,
                        $amount,
                        'pembelian',
                        'PIN tidak valid.'
                    );

                    return [
                        'status' => 'error',
                        'message' => 'PIN tidak valid.',
                        'code' => 422
                    ];
                }
            }

            $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                DB::rollback();

                LogFailedTransaction::format(
                    $card->user_id,
                    $card->id,
                    $canteen->id,
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

            $canteen->update([
                'current_balance' => $canteen->current_balance + $amount
            ]);

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => $canteen->id,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount,
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
                    'canteen_id' => $canteen->id,
                    'timestamp' => $transaction->created_at
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $card->user_id ?? null;
            $cardId = $card->id ?? null;
            $canteenId = $canteen->id ?? null;

            if ($userId && $cardId && $canteenId) {
                LogFailedTransaction::format(
                    $userId,
                    $cardId,
                    $canteenId,
                    $amount,
                    'pembelian',
                    'Kesalahan pada server.'
                );
            }

            return [
                'status' => 'error',
                'message' => 'Transaksi pembelian gagal.',
                'code' => 500,
            ];
        }
    }

    public function getCanteenTransactionHistory(?string $type, ?string $range, int $perPage): array
    {
        $validTypes = ['pembelian', 'refund'];

        $query = Transaction::query()
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type && in_array($type, $validTypes)) {
            $query->where('type', $type);
        } elseif ($type) {
            return [
                'status' => 'error',
                'message' => 'Tipe transaksi tidak valid. Hanya pembelian atau refund yang diizinkan.',
                'code' => 400
            ];
        }

        if ($range) {
            $now = now();
            switch ($range) {
                case 'harian':
                    $query->whereDate('created_at', $now->toDateString());
                    break;
                case 'mingguan':
                    $query->whereBetween('created_at', [$now->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'bulanan':
                    $query->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year);
                    break;
            }
        }

        $transactionHistory = $query->paginate($perPage);

        if ($transactionHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat transaksi.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat transaksi berhasil didapatkan.',
            'code' => 200,
            'data' => $transactionHistory
        ];
    }

    public function getPersonalTransactionHistory(?string $type, ?string $range, int $perPage, int $userId): array
    {
        $query = Transaction::where('user_id', $userId)
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        if ($range) {
            $now = now();
            switch ($range) {
                case 'harian':
                    $query->whereDate('created_at', $now->toDateString());
                    break;
                case 'mingguan':
                    $query->whereBetween('created_at', [$now->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'bulanan':
                    $query->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year);
                    break;
            }
        }

        $transactionHistory = $query->paginate($perPage);

        if ($transactionHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat transaksi.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat transaksi berhasil didapatkan.',
            'code' => 200,
            'data' => $transactionHistory
        ];
    }
}
