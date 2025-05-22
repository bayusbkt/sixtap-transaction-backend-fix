<?php

namespace App\Helpers;

use App\Models\Transaction;

class LogFailedTransaction
{
    public static function format($userId, $cardId, $canteenId, $amount, $type, $note = null): void
    {
        Transaction::create([
            'user_id' => $userId,
            'rfid_card_id' => $cardId,
            'canteen_id' => $canteenId,
            'type' => $type,
            'status' => 'gagal',
            'amount' => $amount,
            'note' => $note
        ]);
    }
}