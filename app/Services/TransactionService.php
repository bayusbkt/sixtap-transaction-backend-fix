<?php

namespace App\Services;

use App\Helpers\HandleEmailNotification;
use App\Helpers\LogFailedTransaction;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;


class TransactionService
{
    public function handleTopUp(string $cardUid, int $amount): array
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

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $amount;

            $wallet->update([
                'balance' => $newBalance,
                'last_top_up' => now()
            ]);

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'berhasil',
                'amount' => $amount,

            ]);

            $dataCard = $card->load('user');

            DB::commit();

            HandleEmailNotification::topUp($dataCard->user, $amount, $newBalance, $transaction->id);

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

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance - $amount;

            $wallet->update([
                'balance' => $newBalance
            ]);

            $canteen->update([
                'current_balance' => $canteen->current_balance + $amount
            ]);

            $canteenOpenerName = $canteen->opener->name;

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => $canteen->id,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount,
            ]);

            DB::commit();

            HandleEmailNotification::purchase($card->user, $amount, $newBalance, $transaction->id, $canteenOpenerName);

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

    public function handleRefundTransaction(int $transactionId, int $canteenOpenerId, string $note): array
    {

        try {
            DB::beginTransaction();

            $originalTransaction = Transaction::where('id', $transactionId)
                ->where('type', 'pembelian')
                ->where('status', 'berhasil')
                ->with(['user', 'rfidCard', 'canteen'])
                ->first();

            if (!$originalTransaction) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Transaksi pembelian tidak ditemukan atau sudah di-refund.',
                    'code' => 404
                ];
            }

            $existingRefund = Transaction::where('type', 'refund')
                ->where('note', 'like', "%Refund untuk transaksi ID: $transactionId%")
                ->first();

            if ($existingRefund) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Transaksi ini sudah pernah di-refund.',
                    'code' => 422
                ];
            }

            $canteen = Canteen::where('opened_by', $canteenOpenerId)
                ->whereNotNull('opened_at')
                ->whereNull('closed_at')
                ->latest()
                ->first();

            if (!$canteen) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada kantin yang sedang dibuka oleh pengguna ini.',
                    'code' => 404
                ];
            }

            if ($originalTransaction->canteen_id !== $canteen->id) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Refund hanya dapat dilakukan di kantin tempat transaksi asli.',
                    'code' => 422
                ];
            }

            if ($canteen->current_balance < $originalTransaction->amount) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Saldo kantin tidak mencukupi untuk melakukan refund.',
                    'code' => 422
                ];
            }

            $wallet = Wallet::where('user_id', $originalTransaction->user_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $originalTransaction->amount;

            $wallet->update([
                'balance' => $newBalance
            ]);

            $canteen->update([
                'current_balance' => $canteen->current_balance - $originalTransaction->amount
            ]);

            $refundTransaction = Transaction::create([
                'user_id' => $originalTransaction->user_id,
                'rfid_card_id' => $originalTransaction->rfid_card_id,
                'canteen_id' => $canteen->id,
                'type' => 'refund',
                'status' => 'berhasil',
                'amount' => $originalTransaction->amount,
                'note' => 'Refund untuk transaksi ID: ' . $transactionId . ' - ' . $note
            ]);

            DB::commit();

            HandleEmailNotification::refund(  
            $originalTransaction->user, 
            $originalTransaction->amount, 
            $newBalance, 
            $refundTransaction->id, 
            $transactionId, 
            $note);

            return [
                'status' => 'success',
                'message' => 'Refund transaksi berhasil dilakukan.',
                'code' => 200,
                'data' => [
                    'refund_transaction_id' => $refundTransaction->id,
                    'original_transaction_id' => $originalTransaction->id,
                    'user' => $originalTransaction->user->only(['id', 'name']),
                    'refund_amount' => $originalTransaction->amount,
                    'canteen_id' => $canteen->id,
                    'timestamp' => $refundTransaction->created_at,
                    'note' => $note
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $originalTransaction->user_id ?? null;
            $cardId = $originalTransaction->rfid_card_id ?? null;
            $canteenId = $canteen->id ?? null;
            $amount = $originalTransaction->amount ?? 0;

            LogFailedTransaction::format(
                $userId,
                $cardId,
                $canteenId,
                $amount,
                'refund',
                'Kesalahan pada server saat melakukan refund.'
            );

            return [
                'status' => 'error',
                'message' => 'Refund transaksi gagal.',
                'code' => 500,
            ];
        }
    }

    public function getTransactionRefundHistory(?string $range = null, int $perPage, ?int $canteenId): array
    {
        try {
            $query = Transaction::where('type', 'refund')
                ->where('status', 'berhasil')
                ->with([
                    'user:id,name,batch,schoolclass_id',
                    'user.schoolClass:id,class_name',
                    'rfidCard:id,card_uid',
                    'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                    'canteen.opener:id,name'
                ])
                ->orderBy('created_at', 'desc');

            if ($canteenId) {
                $query->where('canteen_id', $canteenId);
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
                    default:
                        return [
                            'status' => 'error',
                            'message' => 'Parameter range tidak valid. Gunakan: harian, mingguan, atau bulanan.',
                            'code' => 400
                        ];
                }
            }

            $refundHistory = $query->paginate($perPage);

            if ($refundHistory->isEmpty()) {
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada riwayat refund.',
                    'code' => 404
                ];
            }

            $totalRefundAmount = $query->sum('amount');

            return [
                'status' => 'success',
                'message' => 'Riwayat refund berhasil didapatkan.',
                'code' => 200,
                'data' => [
                    'summary' => [
                        'total_refund_amount' => $totalRefundAmount,
                        'total_refund_transactions' => $refundHistory->total(),
                    ],
                    'refund_history' => $refundHistory
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil riwayat refund.',
                'code' => 500,
            ];
        }
    }
}
