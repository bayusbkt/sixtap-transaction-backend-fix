<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportRepository
{
    public function getTransactionCountByDateRange(Carbon $startDateTime, Carbon $endDateTime): int
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])->count();
    }

    public function getTotalAmountByDateRange(Carbon $startDateTime, Carbon $endDateTime): int
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])->sum('amount') ?? 0;
    }

    public function getTransactionsByType(Carbon $startDateTime, Carbon $endDateTime)
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->selectRaw('type, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');
    }

    public function getUniqueUsersCount(Carbon $startDateTime, Carbon $endDateTime): int
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->distinct('user_id')
            ->count('user_id');
    }

    public function getMaxTransactionAmount(Carbon $startDateTime, Carbon $endDateTime): int
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->max('amount') ?? 0;
    }

    public function getMinTransactionAmount(Carbon $startDateTime, Carbon $endDateTime): int
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->min('amount') ?? 0;
    }

    public function getTransactionsPerDay(Carbon $startDateTime, Carbon $endDateTime)
    {
        return Transaction::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }
}