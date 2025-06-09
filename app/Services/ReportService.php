<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    public function generateTransactionReport(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $specificDate = null,
        ?string $range = null,
    ): array {
        try {
            $timezone = 'Asia/Jakarta';
            $now = Carbon::now($timezone);

            $isValidDate = fn($date) => strtotime($date) !== false;

            if ($specificDate) {
                if (!$isValidDate($specificDate)) {
                    return [
                        'status' => 'error',
                        'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                        'code' => 400,
                    ];
                }
                $date = Carbon::parse($specificDate, $timezone);
                $startDateTime = $date->copy()->startOfDay();
                $endDateTime = $date->copy()->endOfDay();
            } elseif ($startDate && $endDate) {
                if (!$isValidDate($startDate) || !$isValidDate($endDate)) {
                    return [
                        'status' => 'error',
                        'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                        'code' => 400,
                    ];
                }
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $end = Carbon::parse($endDate, $timezone)->endOfDay();

                if ($start->gt($end)) {
                    return [
                        'status' => 'error',
                        'message' => 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.',
                        'code' => 400,
                    ];
                }

                $startDateTime = $start;
                $endDateTime = $end;
            } elseif ($startDate) {
                if (!$isValidDate($startDate)) {
                    return [
                        'status' => 'error',
                        'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
                        'code' => 400,
                    ];
                }
                $start = Carbon::parse($startDate, $timezone)->startOfDay();
                $startDateTime = $start;
                $endDateTime = $now->copy()->endOfDay();
            } elseif ($range) {
                switch ($range) {
                    case 'daily':
                        $startDateTime = $now->copy()->startOfDay();
                        $endDateTime = $now->copy()->endOfDay();
                        break;
                    case 'weekly':
                        $startDateTime = $now->copy()->startOfWeek();
                        $endDateTime = $now->copy()->endOfWeek();
                        break;
                    case 'monthly':
                        $startDateTime = $now->copy()->startOfMonth();
                        $endDateTime = $now->copy()->endOfMonth();
                        break;
                    case 'yearly':
                        $startDateTime = $now->copy()->startOfYear();
                        $endDateTime = $now->copy()->endOfYear();
                        break;
                    default:
                        return [
                            'status' => 'error',
                            'message' => 'Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.',
                            'code' => 400
                        ];
                }
            } else {
                $startDateTime = $now->copy()->startOfMonth();
                $endDateTime = $now->copy()->endOfMonth();
            }

            $baseQuery = Transaction::whereBetween('created_at', [$startDateTime, $endDateTime]);

            $totalTransactions = (clone $baseQuery)->count();
            $totalAmount = (clone $baseQuery)->sum('amount');

            $transactionsByType = (clone $baseQuery)
                ->selectRaw('type, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('type')
                ->get()
                ->keyBy('type');

            // Extract transaction types
            $topUp = $transactionsByType->get('top up');
            $purchase = $transactionsByType->get('pembelian');
            $refund = $transactionsByType->get('refund');
            $withdrawal = $transactionsByType->get('pencairan');

            // Financial summaries
            $canteenIncoming = (int) ($purchase->total_amount ?? 0); // from student purchases
            $balanceOut = (int) ($refund->total_amount ?? 0) + (int) ($withdrawal->total_amount ?? 0);
            $totalTopUp = (int) ($topUp->total_amount ?? 0); // from admin

            // Additional statistics
            $totalUniqueUsers = (clone $baseQuery)->distinct('user_id')->count('user_id');
            $avgTransactionsPerUser = $totalUniqueUsers > 0 ? $totalTransactions / $totalUniqueUsers : 0;
            $avgAmountPerTransaction = $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0;
            $maxTransactionAmount = (clone $baseQuery)->max('amount') ?? 0;
            $minTransactionAmount = (clone $baseQuery)->min('amount') ?? 0;

            // Distribution per day
            $transactionsPerDay = (clone $baseQuery)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get();

            return [
                'status' => 'success',
                'message' => 'Berhasil membuat laporan transaksi.',
                'data' => [
                    'period' => [
                        'type' => $range ?? 'custom',
                        'start_date' => $startDateTime->format('Y-m-d H:i:s'),
                        'end_date' => $endDateTime->format('Y-m-d H:i:s'),
                    ],
                    'summary' => [
                        'total_transactions' => $totalTransactions,
                        'total_amount' => $totalAmount,
                    ],
                    'financial_summary' => [
                        'canteen_income_from_student_transactions' => $canteenIncoming,
                        'total_top_up_by_admin' => $totalTopUp,
                        'balance_out_refund_and_withdrawal' => $balanceOut,
                        'net_school_balance' => ($totalTopUp - $balanceOut),
                    ],
                    'transaction_types' => [
                        'top_up' => [
                            'count' => $topUp?->count ?? 0,
                            'total_amount' => $totalTopUp,
                        ],
                        'purchase' => [
                            'count' => $purchase?->count ?? 0,
                            'total_amount' => $canteenIncoming,
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
                    'user_statistics' => [
                        'average_transactions_per_user' => round($avgTransactionsPerUser, 2),
                        'average_amount_per_transaction' => round($avgAmountPerTransaction, 2),
                        'max_transaction_amount' => $maxTransactionAmount,
                        'min_transaction_amount' => $minTransactionAmount,
                    ],
                    'daily_distribution' => $transactionsPerDay->map(function ($item) {
                        return [
                            'date' => $item->date,
                            'transaction_count' => $item->count,
                            'total_amount' => (int) $item->total_amount,
                        ];
                    }),
                    'generated_at' => now()->format('Y-m-d H:i:s'),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan pada sisi server.',
                'code' => 500,
            ];
        }
    }
}
