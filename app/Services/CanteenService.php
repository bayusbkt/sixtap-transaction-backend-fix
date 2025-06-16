<?php

namespace App\Services;

use App\Helpers\HandleServiceResponse;
use App\Repositories\CanteenRepository;
use Illuminate\Support\Facades\DB;

class CanteenService
{
    protected $canteenRepository;

    public function __construct(CanteenRepository $canteenRepository)
    {
        $this->canteenRepository = $canteenRepository;
    }

    public function requestOpenCanteen(int $userId): array
    {
        $user = $this->canteenRepository->findUser($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        if (!$this->isCanteenGuard($user)) {
            return HandleServiceResponse::errorResponse('Anda bukan penjaga kantin.', 403);
        }

        $alreadyOpened = $this->canteenRepository->findOpenCanteenForToday($userId);

        if ($alreadyOpened) {
            return HandleServiceResponse::errorResponse('Kantin sudah dibuka hari ini.', 409);
        }

        try {
            DB::beginTransaction();

            $canteen = $this->canteenRepository->createCanteen([
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_settled' => false,
                'opened_by' => $userId,
                'opened_at' => now(),
                'closed_at' => null,
                'note' => null
            ]);

            DB::commit();

            return HandleServiceResponse::successResponse('Kantin berhasil dibuka.', [
                'canteen_id' => $canteen->id,
                'opened_at' => $canteen->opened_at,
                'opened_by' => $user->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat membuka kantin.', 500);
        }
    }

    public function settleCanteen(int $userId, ?string $note): array
    {
        $user = $this->canteenRepository->findUser($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        if (!$this->isCanteenGuard($user)) {
            return HandleServiceResponse::errorResponse('Anda bukan penjaga kantin.', 403);
        }

        try {
            DB::beginTransaction();

            $canteen = $this->canteenRepository->findActiveCanteenSession($userId);

            if (!$canteen) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Tidak ada sesi kantin yang sedang berjalan.', 404);
            }

            if (!empty($canteen->settlement_time) || $canteen->is_settled) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Kantin sudah di-settle oleh penjaga kantin.', 422);
            }

            $netProfit = $canteen->current_balance - $canteen->initial_balance;

            $this->canteenRepository->updateCanteen($canteen, [
                'is_settled' => true,
                'settlement_time' => now(),
                'note' => $note ?? null
            ]);

            DB::commit();

            return HandleServiceResponse::successResponse('Kantin berhasil di-settle.', [
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
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat settle kantin.', 500);
        }
    }

    public function closeCanteen(int $userId): array
    {
        $user = $this->canteenRepository->findUser($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        if (!$this->isCanteenGuard($user)) {
            return HandleServiceResponse::errorResponse('Anda bukan penjaga kantin.', 403);
        }

        try {
            DB::beginTransaction();

            $canteen = $this->canteenRepository->findActiveCanteenSession($userId);

            if (!$canteen) {
                DB::rollBack();
                return HandleServiceResponse::errorResponse('Tidak ada sesi kantin yang sedang berjalan.', 404);
            }

            if (!empty($canteen->closed_at)) {
                DB::rollBack();
                return HandleServiceResponse::errorResponse('Kantin sudah ditutup sebelumnya.', 404);
            }

            $this->canteenRepository->updateCanteen($canteen, [
                'closed_at' => now()
            ]);

            DB::commit();

            return HandleServiceResponse::successResponse('Kantin berhasil ditutup.', [
                'canteen_id' => $canteen->id,
                'closed_at' => $canteen->closed_at,
                'opened_at' => $canteen->opened_at,
                'opened_by' => $user->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat menutup kantin.', 500);
        }
    }

    public function inputInitialFund(int $userId, int $amount): array
    {
        $user = $this->canteenRepository->findUser($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        if (!$this->isCanteenGuard($user)) {
            return HandleServiceResponse::errorResponse('Anda bukan penjaga kantin.', 403);
        }

        if ($amount < 0) {
            return HandleServiceResponse::errorResponse('Jumlah saldo tidak boleh negatif.', 422);
        }

        try {
            DB::beginTransaction();

            $canteen = $this->canteenRepository->findActiveCanteenSession($userId);

            if (!$canteen) {
                return HandleServiceResponse::errorResponse('Belum ada sesi kantin yang dibuka.', 404);
            }

            if ($canteen->initial_balance > 0) {
                return HandleServiceResponse::errorResponse('Modal awal sudah ditambahkan sebelumnya', 422);
            }

            $this->canteenRepository->updateCanteen($canteen, [
                'initial_balance' => $amount
            ]);

            DB::commit();

            return HandleServiceResponse::successResponse('Saldo awal kantin berhasil disimpan.', [
                'canteen_id' => $canteen->id,
                'initial_balance' => (int) $canteen->initial_balance,
                'opened_at' => $canteen->opened_at,
                'opened_by' => $user->name
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat menyimpan saldo awal kantin.', 500);
        }
    }

    public function getCanteenInitialFundHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        $paginatedCanteens = $this->canteenRepository->getCanteenHistoryByDateRange(
            null,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        $canteens = $paginatedCanteens->items();

        if (empty($canteens)) {
            return HandleServiceResponse::errorResponse('Tidak ada data riwayat modal awal kantin yang ditemukan.', 404);
        }

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

        return HandleServiceResponse::successResponse('Riwayat modal awal kantin berhasil diambil.', [
            'history' => $historyData,
            'summary' => [
                'total_sessions' => count($historyData),
                'total_initial_fund' => (int) $totalInitialFund,
            ],
        ]);
    }

    public function getCanteenIncomeHistory(
        int $canteenId,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        $paginatedCanteens = $this->canteenRepository->getCanteenHistoryByDateRange(
            $canteenId,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        $canteens = $paginatedCanteens->items();

        if (empty($canteens)) {
            return HandleServiceResponse::errorResponse('Tidak ada riwayat pemasukan kantin berdasarkan ID ini.', 404);
        }

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

        return HandleServiceResponse::successResponse('Riwayat pendapatan kantin berhasil diambil.', [
            'history' => $historyData,
            'summary' => [
                'total_sessions' => count($historyData),
                'total_income' => (int) $totalIncome,
                'total_profit' => (int) $totalProfit,
            ],
        ]);
    }

    public function getGeneralCanteenIncomeHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        $paginatedCanteens = $this->canteenRepository->getCanteenHistoryByDateRange(
            null,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        $canteens = $paginatedCanteens->items();

        if (empty($canteens)) {
            return HandleServiceResponse::errorResponse('Tidak ada data riwayat pemasukan kantin yang ditemukan.', 404);
        }

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

        return HandleServiceResponse::successResponse('Riwayat pendapatan seluruh kantin berhasil diambil.', [
            'history' => $historyData,
            'summary' => [
                'total_sessions' => count($historyData),
                'total_income' => (int) $totalIncome,
                'total_profit' => (int) $totalProfit,
            ],
        ]);
    }

    private function isCanteenGuard($user): bool
    {
        return strtolower($user->role->role_name) === 'penjaga kantin';
    }

    private function validateDateParameters(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range
    ): ?array {
        if ($specificDate && !$this->canteenRepository->validateDateFormat($specificDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($startDate && !$this->canteenRepository->validateDateFormat($startDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($endDate && !$this->canteenRepository->validateDateFormat($endDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($startDate && $endDate && !$this->canteenRepository->validateDateRange($startDate, $endDate)) {
            return HandleServiceResponse::errorResponse('Tanggal mulai tidak boleh lebih besar dari tanggal akhir.', 400);
        }

        if ($range && !$this->canteenRepository->isValidRange($range)) {
            return HandleServiceResponse::errorResponse('Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.', 400);
        }

        return null;
    }
}
