<?php

namespace App\Services;

use App\Repositories\ReportRepository;
use App\Helpers\HandleServiceResponse;
use Carbon\Carbon;

class ReportService
{
    protected $reportRepository;

    public function __construct(ReportRepository $reportRepository)
    {
        $this->reportRepository = $reportRepository;
    }

    public function generateTransactionReport(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $specificDate = null,
        ?string $range = null,
    ): array {
        try {
            // Validate and parse dates
            $dateTimeResult = $this->parseDateTimeRange($startDate, $endDate, $specificDate, $range);
            
            if ($dateTimeResult['status'] === 'error') {
                return $dateTimeResult;
            }

            $startDateTime = $dateTimeResult['start_datetime'];
            $endDateTime = $dateTimeResult['end_datetime'];
            $rangeType = $dateTimeResult['range_type'];

            // Get basic statistics
            $totalTransactions = $this->reportRepository->getTransactionCountByDateRange($startDateTime, $endDateTime);
            $totalAmount = $this->reportRepository->getTotalAmountByDateRange($startDateTime, $endDateTime);

            // Get transactions by type
            $transactionsByType = $this->reportRepository->getTransactionsByType($startDateTime, $endDateTime);

            // Extract transaction types
            $topUp = $transactionsByType->get('top up');
            $purchase = $transactionsByType->get('pembelian');
            $refund = $transactionsByType->get('refund');
            $withdrawal = $transactionsByType->get('pencairan');

            // Calculate financial summaries
            $financialSummary = $this->calculateFinancialSummary($topUp, $purchase, $refund, $withdrawal);

            // Get user statistics
            $userStats = $this->calculateUserStatistics($startDateTime, $endDateTime, $totalTransactions, $totalAmount);

            // Get daily distribution
            $dailyDistribution = $this->getDailyDistribution($startDateTime, $endDateTime);

            return HandleServiceResponse::successResponse('Berhasil membuat laporan transaksi.', [
                'period' => [
                    'type' => $rangeType,
                    'start_date' => $startDateTime->format('Y-m-d H:i:s'),
                    'end_date' => $endDateTime->format('Y-m-d H:i:s'),
                ],
                'summary' => [
                    'total_transactions' => $totalTransactions,
                    'total_amount' => $totalAmount,
                ],
                'financial_summary' => $financialSummary,
                'transaction_types' => [
                    'top_up' => [
                        'count' => $topUp?->count ?? 0,
                        'total_amount' => (int) ($topUp->total_amount ?? 0),
                    ],
                    'purchase' => [
                        'count' => $purchase?->count ?? 0,
                        'total_amount' => (int) ($purchase->total_amount ?? 0),
                    ],
                    'refund' => [
                        'count' => $refund?->count ?? 0,
                        'total_amount' => (int) ($refund->total_amount ?? 0),
                    ],
                    'withdrawal' => [
                        'count' => $withdrawal?->count ?? 0,
                        'total_amount' => (int) ($withdrawal->total_amount ?? 0),
                    ],
                ],
                'user_statistics' => $userStats,
                'daily_distribution' => $dailyDistribution,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan pada sisi server saat membuat laporan transaksi.', 500);
        }
    }

    private function parseDateTimeRange(?string $startDate, ?string $endDate, ?string $specificDate, ?string $range): array
    {
        $timezone = 'Asia/Jakarta';
        $now = Carbon::now($timezone);

        $isValidDate = fn($date) => strtotime($date) !== false;

        if ($specificDate) {
            if (!$isValidDate($specificDate)) {
                return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
            }
            $date = Carbon::parse($specificDate, $timezone);
            return [
                'status' => 'success',
                'start_datetime' => $date->copy()->startOfDay(),
                'end_datetime' => $date->copy()->endOfDay(),
                'range_type' => 'specific_date'
            ];
        }

        if ($startDate && $endDate) {
            if (!$isValidDate($startDate) || !$isValidDate($endDate)) {
                return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
            }
            $start = Carbon::parse($startDate, $timezone)->startOfDay();
            $end = Carbon::parse($endDate, $timezone)->endOfDay();

            if ($start->gt($end)) {
                return HandleServiceResponse::errorResponse('Tanggal mulai tidak boleh lebih besar dari tanggal akhir.', 400);
            }

            return [
                'status' => 'success',
                'start_datetime' => $start,
                'end_datetime' => $end,
                'range_type' => 'custom'
            ];
        }

        if ($startDate) {
            if (!$isValidDate($startDate)) {
                return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
            }
            $start = Carbon::parse($startDate, $timezone)->startOfDay();
            return [
                'status' => 'success',
                'start_datetime' => $start,
                'end_datetime' => $now->copy()->endOfDay(),
                'range_type' => 'from_date'
            ];
        }

        if ($range) {
            switch ($range) {
                case 'harian':
                    return [
                        'status' => 'success',
                        'start_datetime' => $now->copy()->startOfDay(),
                        'end_datetime' => $now->copy()->endOfDay(),
                        'range_type' => 'harian'
                    ];
                case 'mingguan':
                    return [
                        'status' => 'success',
                        'start_datetime' => $now->copy()->startOfWeek(),
                        'end_datetime' => $now->copy()->endOfWeek(),
                        'range_type' => 'mingguan'
                    ];
                case 'bulanan':
                    return [
                        'status' => 'success',
                        'start_datetime' => $now->copy()->startOfMonth(),
                        'end_datetime' => $now->copy()->endOfMonth(),
                        'range_type' => 'bulanan'
                    ];
                case 'tahunan':
                    return [
                        'status' => 'success',
                        'start_datetime' => $now->copy()->startOfYear(),
                        'end_datetime' => $now->copy()->endOfYear(),
                        'range_type' => 'tahunan'
                    ];
                default:
                    return HandleServiceResponse::errorResponse('Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.', 400);
            }
        }

        return [
            'status' => 'success',
            'start_datetime' => $now->copy()->startOfMonth(),
            'end_datetime' => $now->copy()->endOfMonth(),
            'range_type' => 'bulanan'
        ];
    }

    private function calculateFinancialSummary($topUp, $purchase, $refund, $withdrawal): array
    {
        $canteenIncoming = (int) ($purchase->total_amount ?? 0);
        $totalTopUp = (int) ($topUp->total_amount ?? 0);
        $refundAmount = (int) ($refund->total_amount ?? 0);
        $withdrawalAmount = (int) ($withdrawal->total_amount ?? 0);
        $balanceOut = $refundAmount + $withdrawalAmount;

        return [
            'canteen_income_from_student_transactions' => $canteenIncoming,
            'total_top_up_by_admin' => $totalTopUp,
            'balance_out_refund_and_withdrawal' => $balanceOut,
            'net_school_balance' => ($totalTopUp - $balanceOut),
        ];
    }

    private function calculateUserStatistics($startDateTime, $endDateTime, int $totalTransactions, int $totalAmount): array
    {
        $totalUniqueUsers = $this->reportRepository->getUniqueUsersCount($startDateTime, $endDateTime);
        $avgTransactionsPerUser = $totalUniqueUsers > 0 ? $totalTransactions / $totalUniqueUsers : 0;
        $avgAmountPerTransaction = $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0;
        $maxTransactionAmount = $this->reportRepository->getMaxTransactionAmount($startDateTime, $endDateTime);
        $minTransactionAmount = $this->reportRepository->getMinTransactionAmount($startDateTime, $endDateTime);

        return [
            'average_transactions_per_user' => round($avgTransactionsPerUser, 2),
            'average_amount_per_transaction' => round($avgAmountPerTransaction, 2),
            'max_transaction_amount' => $maxTransactionAmount,
            'min_transaction_amount' => $minTransactionAmount,
        ];
    }

    private function getDailyDistribution($startDateTime, $endDateTime): array
    {
        $transactionsPerDay = $this->reportRepository->getTransactionsPerDay($startDateTime, $endDateTime);

        return $transactionsPerDay->map(function ($item) {
            return [
                'date' => $item->date,
                'transaction_count' => $item->count,
                'total_amount' => (int) $item->total_amount,
            ];
        })->toArray();
    }
}