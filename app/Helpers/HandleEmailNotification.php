<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Mail;
use App\Mail\TopUpNotification;
use App\Mail\PurchaseNotification;
use App\Mail\RefundNotification;
use App\Models\TransactionNotification;

class HandleEmailNotification
{
    public static function topUp($user, $amount, $newBalance, $transactionId): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new TopUpNotification([
                    'user_name' => $user->name,
                    'amount' => $amount,
                    'new_balance' => $newBalance,
                    'transaction_id' => $transactionId,
                    'date' => now()->format('d/m/Y'),
                    'timestamp' => now()->format('H:i')
                ]));
            }

            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Top up berhasil sejumlah Rp ' . $amount,
                'type' => 'top up',
                'status' => 'berhasil',
                'sent_at' => now()
            ]);
        } catch (\Exception $e) {
            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Gagal mengirim notifikasi email top up',
                'type' => 'top up',
                'status' => 'gagal',
                'sent_at' => now()
            ]);
        }
    }

    public static function purchase($user, $amount, $remainingBalance, $transactionId, $canteenOpenerName): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new PurchaseNotification([
                    'user_name' => $user->name,
                    'amount' => $amount,
                    'remaining_balance' => $remainingBalance,
                    'transaction_id' => $transactionId,
                    'canteen_opener' => $canteenOpenerName,
                    'date' => now()->format('d/m/Y'),
                    'timestamp' => now()->format('H:i')
                ]));
            }

            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Pembelian berhasil sejumlah Rp ' . $amount,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'sent_at' => now()
            ]);
        } catch (\Exception $e) {
            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Gagal mengirim notifikasi email pembelian',
                'type' => 'pembelian',
                'status' => 'gagal',
                'sent_at' => now()
            ]);
        }
    }

    public static function refund($user, $amount, $newBalance, $refundTransactionId, $originalTransactionId, $note): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new RefundNotification([
                    'user_name' => $user->name,
                    'refund_amount' => $amount,
                    'new_balance' => $newBalance,
                    'refund_transaction_id' => $refundTransactionId,
                    'original_transaction_id' => $originalTransactionId,
                    'note' => $note,
                    'date' => now()->format('d/m/Y'),
                    'timestamp' => now()->format('H:i')
                ]));
            }

            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Refund berhasil sejumlah Rp ' . $amount,
                'type' => 'top up',
                'status' => 'berhasil',
                'sent_at' => now()
            ]);
        } catch (\Exception $e) {
            TransactionNotification::create([
                'user_id' => $user->id,
                'message' => 'Gagal mengirim notifikasi email refund',
                'type' => 'refund',
                'status' => 'gagal',
                'sent_at' => now()
            ]);
        }
    }
}
