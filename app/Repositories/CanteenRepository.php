<?php

namespace App\Repositories;

use App\Models\Canteen;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class CanteenRepository
{
    public function findUser(int $userId)
    {
        return User::find($userId);
    }

    public function findOpenCanteenForToday(int $userId)
    {
        return Canteen::whereDate('opened_at', now()->toDateString())
            ->where('opened_by', $userId)
            ->whereNull('closed_at')
            ->first();
    }

    public function findActiveCanteenSession(int $userId)
    {
        return Canteen::where('opened_by', $userId)
            ->whereNull('closed_at')
            ->whereDate('opened_at', now()->toDateString())
            ->latest()
            ->first();
    }

    public function createCanteen(array $data)
    {
        return Canteen::create($data);
    }

    public function updateCanteen(Canteen $canteen, array $data)
    {
        return $canteen->update($data);
    }

    public function getCanteenHistoryByDateRange(
        ?int $canteenId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $specificDate = null,
        ?string $range = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = Canteen::whereNotNull('opened_at');

        if ($canteenId) {
            $query->where('id', $canteenId);
        }

        $timezone = 'Asia/Jakarta';

        if ($specificDate) {
            $date = Carbon::parse($specificDate, $timezone);
            $query->whereDate('created_at', $date->toDateString());
        } elseif ($startDate && $endDate) {
            $start = Carbon::parse($startDate, $timezone)->startOfDay();
            $end = Carbon::parse($endDate, $timezone)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($startDate) {
            $start = Carbon::parse($startDate, $timezone)->startOfDay();
            $query->where('created_at', '>=', $start);
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
            }
        }

        return $query->with(['opener:id,name'])
            ->orderBy('opened_at', 'desc')
            ->paginate($perPage);
    }

    public function validateDateFormat(string $date)
    {
        try {
            Carbon::parse($date);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function validateDateRange(string $startDate, string $endDate)
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            return $start->lte($end);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isValidRange(string $range)
    {
        return in_array($range, ['harian', 'mingguan', 'bulanan', 'tahunan']);
    }
}