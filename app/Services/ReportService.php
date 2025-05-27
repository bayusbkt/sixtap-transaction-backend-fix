<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Canteen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    public function generateFinancialSummary(?string $range = null, ?int $canteenId = null): array
    {
        try {
            $transactionReport = $this->generateTransactionReport($range, $canteenId);

            if ($transactionReport['status'] !== 'success') {
                return $transactionReport;
            }

            $data = $transactionReport['data'];

            $totalIncome = $data['transaction_types']['top_up']['total_amount'];
            $totalExpense = $data['transaction_types']['purchase']['total_amount'];
            $totalRefund = $data['transaction_types']['refund']['total_amount'];

            $netRevenue = $totalExpense - $totalRefund;
            $cashFlow = $totalIncome - $totalExpense + $totalRefund;

            return [
                'status' => 'success',
                'message' => 'Ringkasan keuangan berhasil dibuat.',
                'code' => 200,
                'data' => [
                    'period' => $data['period'],
                    'financial_summary' => [
                        'total_income' => $totalIncome,
                        'total_expense' => $totalExpense,
                        'total_refund' => $totalRefund,
                        'net_revenue' => $netRevenue,
                        'cash_flow' => $cashFlow,
                        'refund_rate' => $totalExpense > 0 ? round($totalRefund / $totalExpense * 100, 2) : 0
                    ],
                    'transaction_summary' => $data['summary'],
                    'generated_at' => now()->format('Y-m-d H:i:s')
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat membuat ringkasan keuangan.',
                'code' => 500,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function generateTransactionReport(?string $range = null, ?int $canteenId = null): array
    {
        try {
            $now = Carbon::now();
            $startDate = null;
            $endDate = null;

            switch ($range) {
                case 'harian':
                    $startDate = $now->copy()->startOfDay();
                    $endDate = $now->copy()->endOfDay();
                    break;
                case 'mingguan':
                    $startDate = $now->copy()->startOfWeek();
                    $endDate = $now->copy()->endOfWeek();
                    break;
                case 'bulanan':
                    $startDate = $now->copy()->startOfMonth();
                    $endDate = $now->copy()->endOfMonth();
                    break;
                case 'tahunan':
                    $startDate = $now->copy()->startOfYear();
                    $endDate = $now->copy()->endOfYear();
                    break;
                default:
                    $startDate = $now->copy()->startOfMonth();
                    $endDate = $now->copy()->endOfMonth();
                    break;
            }

            $baseQuery = Transaction::whereBetween('created_at', [$startDate, $endDate]);

            if ($canteenId) {
                $baseQuery->where('canteen_id', $canteenId);
            }

            $totalTransactions = (clone $baseQuery)->count();
            $totalAmount = (clone $baseQuery)->sum('amount');

            $transactionsByType = (clone $baseQuery)
                ->selectRaw('type, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('type')
                ->get()
                ->keyBy('type');

            $topUpStats = [
                'count' => $transactionsByType->get('top up')?->count ?? 0,
                'total_amount' => $transactionsByType->get('top up')?->total_amount ?? 0
            ];

            $purchaseStats = [
                'count' => $transactionsByType->get('pembelian')?->count ?? 0,
                'total_amount' => $transactionsByType->get('pembelian')?->total_amount ?? 0
            ];

            $refundStats = [
                'count' => $transactionsByType->get('refund')?->count ?? 0,
                'total_amount' => $transactionsByType->get('refund')?->total_amount ?? 0
            ];

            $dailyTrends = $baseQuery->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(amount) as daily_amount'),
                'type'
            )
                ->groupBy(DB::raw('DATE(created_at)'), 'type')
                ->orderBy(DB::raw('DATE(created_at)'), 'asc')
                ->get()
                ->groupBy('date');

            $topUsers = (clone $baseQuery)
                ->selectRaw('user_id, COUNT(*) as transaction_count, SUM(amount) as total_spent')
                ->with(['user' => function ($query) {
                    $query->select('id', 'name', 'batch');
                }])
                ->groupBy('user_id')
                ->orderByDesc('transaction_count')
                ->limit(10)
                ->get();

            $canteenPerformance = [];
            if (!$canteenId) {
                $canteenPerformance = (clone $baseQuery)
                    ->whereNotNull('canteen_id')
                    ->selectRaw('canteen_id, COUNT(*) as transaction_count, SUM(amount) as total_revenue')
                    ->with([
                        'canteen' => function ($query) {
                            $query->select('id', 'opened_by', 'opened_at', 'closed_at');
                        },
                        'canteen.opener' => function ($query) {
                            $query->select('id', 'name');
                        }
                    ])
                    ->groupBy('canteen_id')
                    ->orderByDesc('total_revenue')
                    ->get();
            }

            $statusBreakdown = (clone $baseQuery)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $averageAmounts = $transactionsByType->map(function ($item) {
                return [
                    'type' => $item->type,
                    'average_amount' => $item->count > 0 ? round($item->total_amount / $item->count, 2) : 0,
                    'count' => $item->count,
                    'total_amount' => $item->total_amount
                ];
            });

            $peakHours = $baseQuery->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as transaction_count')
            )
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy('transaction_count', 'desc')
                ->limit(5)
                ->get();

            return [
                'status' => 'success',
                'message' => 'Laporan transaksi berhasil dibuat.',
                'code' => 200,
                'data' => [
                    'period' => [
                        'range' => $range ?? 'bulanan',
                        'start_date' => $startDate->format('Y-m-d H:i:s'),
                        'end_date' => $endDate->format('Y-m-d H:i:s'),
                        'canteen_id' => $canteenId
                    ],
                    'summary' => [
                        'total_transactions' => $totalTransactions,
                        'total_amount' => $totalAmount,
                        'success_rate' => $totalTransactions > 0 ? round(($statusBreakdown->get('berhasil')?->count ?? 0) / $totalTransactions * 100, 2) : 0
                    ],
                    'transaction_types' => [
                        'top_up' => $topUpStats,
                        'purchase' => $purchaseStats,
                        'refund' => $refundStats
                    ],
                    'daily_trends' => $dailyTrends,
                    'top_users' => $topUsers,
                    'canteen_performance' => $canteenPerformance,
                    'status_breakdown' => $statusBreakdown,
                    'average_amounts' => $averageAmounts,
                    'peak_hours' => $peakHours,
                    'generated_at' => now()->format('Y-m-d H:i:s')
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat membuat laporan transaksi.',
                'code' => 500,
                'error' => $e->getMessage()
            ];
        }
    }
}
