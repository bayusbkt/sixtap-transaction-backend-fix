<?php

namespace App\Services;

use App\Models\Canteen;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CanteenService
{
    public function requestOpenCanteen(int $userId): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.',
                'code' => 404,
            ];
        }

        if (strtolower($user->role->role_name) !== 'penjaga kantin') {
            return [
                'status' => 'error',
                'message' => 'Anda bukan penjaga kantin.',
                'code' => 403,
            ];
        }

        $alreadyOpened = Canteen::whereDate('opened_at', now()->toDateString())
            ->where('opened_by', $userId)
            ->whereNull('closed_at')
            ->exists();

        if ($alreadyOpened) {
            return [
                'status' => 'error',
                'message' => 'Kantin sudah dibuka hari ini.',
                'code' => 409,
            ];
        }

        try {
            DB::beginTransaction();

            $canteen = Canteen::create([
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_settled' => false,
                'opened_by' => $userId,
                'opened_at' => now(),
                'closed_at' => null,
                'note' => null
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Kantin berhasil dibuka.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,
                    'opened_at' => $canteen->opened_at,
                    'opened_by' => $user->name,
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat membuka kantin.',
                'code' => 500
            ];
        }
    }

    public function settleCanteen(int $userId, ?string $note): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.',
                'code' => 404,
            ];
        }

        if (strtolower($user->role->role_name) !== 'penjaga kantin') {
            return [
                'status' => 'error',
                'message' => 'Anda bukan penjaga kantin.',
                'code' => 403,
            ];
        }

        try {
            DB::beginTransaction();

            $canteen = Canteen::where('opened_by', $userId)
                ->whereNull('closed_at')
                ->whereDate('opened_at', now()->toDateString())
                ->latest()
                ->first();

            if (!$canteen) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada sesi kantin yang sedang berjalan.',
                    'code' => 404,
                ];
            }

            $netProfit = $canteen->current_balance - $canteen->initial_balance;

            $canteen->update([
                'is_settled' => true,
                'settlement_time' => now(),
                'note' => $note ?? null
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Kantin berhasil diselesaikan.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,
                    'initial_balance' => $canteen->initial_balance,
                    'current_balance' => $canteen->current_balance,
                    'net_profit' => $netProfit,
                    'is_settled' => $canteen->is_settled,
                    'settlement_time' => $canteen->settlement_time,
                    'opened_at' => $canteen->opened_at,
                    'opened_by' => $user->name,
                    'closed_at' => $canteen->closed_at,
                    'note' => $canteen->note,
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menutup kantin.',
                'code' => 500
            ];
        }
    }

    public function closeCanteen(int $userId): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.',
                'code' => 404,
            ];
        }

        if (strtolower($user->role->role_name) !== 'penjaga kantin') {
            return [
                'status' => 'error',
                'message' => 'Anda bukan penjaga kantin.',
                'code' => 403,
            ];
        }

        try {
            DB::beginTransaction();

            $canteen = Canteen::where('opened_by', $userId)
                ->whereNull('closed_at')
                ->whereDate('opened_at', now()->toDateString())
                ->latest()
                ->first();

            if (!$canteen) {
                DB::rollBack();
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada sesi kantin yang sedang berjalan.',
                    'code' => 404,
                ];
            }

            $canteen->update([
                'closed_at' => now()
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Kantin berhasil ditutup.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,
                    'closed_at' => $canteen->closed_at,
                    'opened_at' => $canteen->opened_at,
                    'opened_by' => $user->name,
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menutup kantin.',
                'code' => 500,
            ];
        }
    }


    public function inputInitialFund(int $userId, int $amount): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.',
                'code' => 404,
            ];
        }

        if (strtolower($user->role->role_name) !== 'penjaga kantin') {
            return [
                'status' => 'error',
                'message' => 'Anda bukan penjaga kantin.',
                'code' => 403,
            ];
        }

        try {
            DB::beginTransaction();

            $canteen = Canteen::where('opened_by', $userId)
                ->whereNull('closed_at')
                ->whereDate('opened_at', now()->toDateString())
                ->latest()
                ->first();

            if (!$canteen) {
                return [
                    'status' => 'error',
                    'message' => 'Belum ada sesi kantin yang dibuka.',
                    'code' => 404,
                ];
            }

            if ($amount < 0) {
                return [
                    'status' => 'error',
                    'message' => 'Jumlah saldo tidak boleh negatif.',
                    'code' => 422,
                ];
            }

            $canteen->update([
                'initial_balance' => $amount
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Saldo awal kantin berhasil disimpan.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,
                    'initial_balance' => $canteen->initial_balance,
                    'opened_at' => $canteen->opened_at,
                    'opened_by' => $user->name
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menyimpan saldo awal kantin.',
                'code' => 500,
            ];
        }
    }
}
