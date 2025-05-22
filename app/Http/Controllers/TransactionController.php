<?php

namespace App\Http\Controllers;

use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    public function topUp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_uid' => 'required|string',
            'amount' => 'required|integer|min:500',
        ], [
            'card_uid.required' => 'UID kartu wajib diisi.',
            'amount.required' => 'Nominal wajib diisi.',
            'amount.integer' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal minimal adalah Rp 500.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $card = RfidCard::where('card_uid', $request->card_uid)->first();

        if (!$card) {
            return response()->json([
                'message' => 'Kartu tidak ditemukan.'
            ], 404);
        }

        if ($card->is_active == false) {
            return response()->json([
                'message' => 'Kartu tidak aktif.'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

            if (!$wallet) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Wallet pengguna tidak ditemukan.'
                ], 404);
            }

            $wallet->update([
                'balance' => $wallet->balance + $request->amount,
                'last_top_up' => now()
            ]);

            Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'berhasil',
                'amount' => $request->amount
            ]);

            $dataCard = $card->load('user');

            DB::commit();

            return response()->json([
                'message' => 'Top up berhasil.',
                'data' => [
                    'card' => $dataCard
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'gagal',
                'amount' => $request->amount
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat memproses top up.',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function topUpHistory()
    {
        $topUpHistory = Transaction::where('type', 'top up')->with('user')->orderBy('created_at', 'desc')->paginate(50);

        if ($topUpHistory->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada riwayat top up.'
            ], 404);
        }

        return response()->json([
            'message' => 'Riwayat top up berhasil didapatkan.',
            'data' => $topUpHistory
        ]);
    }

    public function checkBalance($userId)
    {
        $wallet = Wallet::where('user_id', $userId)->first();

        if (!$wallet) {
            return response()->json([
                'message' => 'Wallet pengguna tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'message' => 'Cek saldo berhasil.',
            'data' => [
                'name' => $wallet->user->name,
                'rfid_card_id' => $wallet->rfidCard->card_uid,
                'balance' => $wallet->balance,
                'last_top_up' => $wallet->last_top_up,
            ]
        ]);
    }

    public function addPin(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|digits:6|integer|min:0',
        ], [
            'pin.required' => 'PIN wajib diisi.',
            'pin.digits'   => 'PIN harus terdiri dari 6 digit angka.',
            'pin.integer'  => 'PIN harus berupa angka.',
            'pin.min'      => 'PIN tidak boleh bernilai negatif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => 'Pengguna tidak ditemukan.'
                ], 404);
            }

            $user->update([
                'pin' => Hash::make($request->pin)
            ]);

            return response()->json([
                'message' => 'PIN berhasil ditambahkan.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat menambahkan PIN.',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function updatePin(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|digits:6|integer|min:0',
        ], [
            'pin.required' => 'PIN wajib diisi.',
            'pin.digits'   => 'PIN harus terdiri dari 6 digit angka.',
            'pin.integer'  => 'PIN harus berupa angka.',
            'pin.min'      => 'PIN tidak boleh bernilai negatif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => 'Pengguna tidak ditemukan.'
                ], 404);
            }

            if ($user->pin && Hash::check($request->pin, $user->pin)) {
            return response()->json([
                'message' => 'PIN tidak boleh sama dengan PIN sebelumnya.'
            ], 422);
        }

            $user->update([
                'pin' => Hash::make($request->pin)
            ]);

            return response()->json([
                'message' => 'PIN berhasil diperbarui.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat update PIN.',
                'error' => $e->getMessage()
            ]);
        }
    }
}
