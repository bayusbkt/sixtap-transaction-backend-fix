<?php

namespace App\Services;

use App\Models\Canteen;
use App\Models\User;
use Illuminate\Support\Carbon;
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

            if (!empty($canteen->settlement_time) || $canteen->is_settled) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kantin sudah di-settle oleh penjaga kantin.',
                    'code' => 422,
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

            if (!empty($canteen->closed_at)) {
                DB::rollBack();
                return [
                    'status' => 'error',
                    'message' => 'Kantin sudah ditutup sebelumnya',
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

            if ($canteen->initial_balance > 0) {
                return [
                    'status' => 'error',
                    'message' => 'Modal awal sudah ditambahkan sebelumnya',
                    'code' => 422
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
                    'initial_balance' => (int) $canteen->initial_balance,
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

    public function getCanteenInitialFundHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $query = Canteen::whereNotNull('opened_at')
            ->orderBy('created_at', 'desc');

        $timezone = 'Asia/Jakarta';

        if ($specificDate) {
            try {
                $date = Carbon::parse($specificDate, $timezone);
                $query->whereDate('created_at', $date->toDateString());
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate && $endDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $end = Carbon::parse($endDate, $timezone)->endOfDay();

                if ($start->gt($end)) {
                    return [
                        'status' => 'error',
                        'message' => 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.',
                        'code' => 400
                    ];
                }

                $query->whereBetween('created_at', [$start, $end]);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $query->where('created_at', '>=', $start);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($range) {
            $now = Carbon::now($timezone);
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
                case 'tahunan':
                    $query->whereYear('created_at', $now->year);
                    break;
                default:
                    return [
                        'status' => 'error',
                        'message' => 'Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.',
                        'code' => 400
                    ];
            }
        }

        $paginatedCanteens = $query->with(['opener:id,name'])
            ->orderBy('opened_at', 'desc')
            ->paginate($perPage);

        $canteens = $paginatedCanteens->items();

        $historyData = [];
        $totalInitialFund = 0;

        foreach ($canteens as $canteen) {
            $totalInitialFund += $canteen->initial_balance;

            $historyData[] = [
                'canteen_id' => $canteen->id,
                'date' => $canteen->opened_at->format('Y-m-d'),
                'opened_at' => $canteen->opened_at,
                'closed_at' => $canteen->closed_at,
                'initial_balance' => (int) $canteen->initial_balance,
                'status' => [
                    'is_open' => $canteen->closed_at === null,
                    'is_settled' => $canteen->is_settled
                ],
                'note' => $canteen->note,
                'opened_by' => [
                    'id' => $canteen->opener->id,
                    'name' => $canteen->opener->name
                ]
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat modal awal kantin berhasil diambil.',
            'code' => 200,
            'data' => [
                'history' => $historyData,
                'summary' => [
                    'total_sessions' => count($historyData),
                    'total_initial_fund' => (int) $totalInitialFund,
                ],
            ]
        ];
    }

    public function getCanteenIncomeHistory(
        int $canteenId,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $query = Canteen::where('id', $canteenId)
            ->whereNotNull('opened_at')
            ->orderBy('created_at', 'desc');

        $timezone = 'Asia/Jakarta';

        if ($specificDate) {
            try {
                $date = Carbon::parse($specificDate, $timezone);
                $query->whereDate('created_at', $date->toDateString());
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate && $endDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $end = Carbon::parse($endDate, $timezone)->endOfDay();

                if ($start->gt($end)) {
                    return [
                        'status' => 'error',
                        'message' => 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.',
                        'code' => 400
                    ];
                }

                $query->whereBetween('created_at', [$start, $end]);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $query->where('created_at', '>=', $start);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($range) {
            $now = Carbon::now($timezone);
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
                case 'tahunan':
                    $query->whereYear('created_at', $now->year);
                    break;
                default:
                    return [
                        'status' => 'error',
                        'message' => 'Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.',
                        'code' => 400
                    ];
            }
        }

        $paginatedCanteens = $query->orderBy('opened_at', 'desc')
            ->paginate($perPage);

        $canteens = $paginatedCanteens->items();

        $historyData = [];
        $totalIncome = 0;
        $totalProfit = 0;

        foreach ($canteens as $canteen) {
            $netProfit = $canteen->current_balance - $canteen->initial_balance;
            $totalIncome += $canteen->current_balance;
            $totalProfit += $netProfit;

            $historyData[] = [
                'canteen_id' => $canteen->id,
                'date' => $canteen->opened_at->format('Y-m-d'),
                'opened_at' => $canteen->opened_at,
                'closed_at' => $canteen->closed_at,
                'settlement_time' => $canteen->settlement_time,
                'initial_balance' => (int) $canteen->initial_balance,
                'current_balance' => (int) $canteen->current_balance,
                'net_profit' => (int) $netProfit,
                'note' => $canteen->note,
                'opened_by' => $canteen->opener->name,
            ];
        }

        $response = [
            'status' => 'success',
            'message' => 'Riwayat pendapatan kantin berhasil diambil.',
            'code' => 200,
            'data' => [
                'history' => $historyData,
                'summary' => [
                    'total_sessions' => count($historyData),
                    'total_income' => (int) $totalIncome,
                    'total_profit' => (int) $totalProfit,
                ],
            ]
        ];

        if (empty($historyData)) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat pendapatan kantin berdasarkan ID ini',
                'code' => 404
            ];
        }

        return $response;
    }

    public function getGeneralCanteenIncomeHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $query = Canteen::whereNotNull('opened_at')
            ->orderBy('created_at', 'desc');

        $timezone = 'Asia/Jakarta';

        if ($specificDate) {
            try {
                $date = Carbon::parse($specificDate, $timezone);
                $query->whereDate('created_at', $date->toDateString());
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate && $endDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $end = Carbon::parse($endDate, $timezone)->endOfDay();

                if ($start->gt($end)) {
                    return [
                        'status' => 'error',
                        'message' => 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.',
                        'code' => 400
                    ];
                }

                $query->whereBetween('created_at', [$start, $end]);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($startDate) {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $query->where('created_at', '>=', $start);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                    'code' => 400
                ];
            }
        } elseif ($range) {
            $now = Carbon::now($timezone);
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
                case 'tahunan':
                    $query->whereYear('created_at', $now->year);
                    break;
                default:
                    return [
                        'status' => 'error',
                        'message' => 'Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.',
                        'code' => 400
                    ];
            }
        }

        $paginatedCanteens = $query->with(['opener:id,name'])
            ->orderBy('opened_at', 'desc')
            ->paginate($perPage);

        $canteens = $paginatedCanteens->items();

        $historyData = [];
        $totalIncome = 0;
        $totalProfit = 0;

        foreach ($canteens as $canteen) {
            $netProfit = $canteen->current_balance - $canteen->initial_balance;

            $totalIncome += $canteen->current_balance;
            $totalProfit += $netProfit;

            $historyData[] = [
                'canteen_id' => $canteen->id,
                'date' => $canteen->opened_at->format('Y-m-d'),
                'opened_at' => $canteen->opened_at,
                'closed_at' => $canteen->closed_at,
                'settlement_time' => $canteen->settlement_time,
                'initial_balance' => (int) $canteen->initial_balance,
                'current_balance' => (int) $canteen->current_balance,
                'net_profit' => (int) $netProfit,
                'note' => $canteen->note,
                'opened_by' => $canteen->opener->name ?? null,
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat pendapatan kantin berhasil diambil.',
            'code' => 200,
            'data' => [
                'history' => $historyData,
                'summary' => [
                    'total_sessions' => count($historyData),
                    'total_income' => (int) $totalIncome,
                    'total_profit' => (int) $totalProfit,
                ],
            ]
        ];
    }
}
