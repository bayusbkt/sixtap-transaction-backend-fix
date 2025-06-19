<?php

namespace App\Repositories;

use App\Models\Absence;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionRepository
{
    public function findRfidCardByUidWithRelation(string $cardUid)
    {
        return RfidCard::where('card_uid', $cardUid)->with('user')->first();
    }

    public function findRfidCardByUid(string $cardUid)
    {
        return RfidCard::where('card_uid', $cardUid)->where('is_active', true)->first();
    }

    public function findWalletByUserId(int $userId, bool $lockForUpdate = false)
    {
        $query = Wallet::where('user_id', $userId);
        
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        
        return $query->first();
    }

    public function updateWallet(Wallet $wallet, array $data)
    {
        return $wallet->update($data);
    }

    public function createTransaction(array $data)
    {
        return Transaction::create($data);
    }

    public function findTransactionById(int $id, array $with = [])
    {
        $query = Transaction::where('id', $id);
        
        if (!empty($with)) {
            $query->with($with);
        }
        
        return $query->first();
    }

    public function findTopUpTransaction(int $transactionId)
    {
        return Transaction::where('id', $transactionId)
            ->where('type', 'top up')
            ->where('status', 'berhasil')
            ->with([
                'user:id,name,email,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
            ])
            ->first();
    }

    public function getTopUpHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage,
        string $timezone = 'Asia/Jakarta'
    ): LengthAwarePaginator {
        $query = Transaction::where('type', 'top up')
            ->with([
                'user:id,name,nis,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid'
            ])
            ->orderBy('created_at', 'desc');

        $this->applyDateFilters($query, $startDate, $endDate, $specificDate, $range, $timezone);

        return $query->paginate($perPage);
    }

    public function findAbsenceForToday(int $userId)
    {
        $today = now()->format('Y-m-d');
        $todayName = now()->format('l');

        return Absence::where('user_id', $userId)
            ->where('day', $todayName)
            ->whereDate('time_in', $today)
            ->first();
    }

    public function findOpenCanteenByOpener(int $canteenOpenerId)
    {
        return Canteen::where('opened_by', $canteenOpenerId)
            ->whereNotNull('opened_at')
            ->whereNull('closed_at')
            ->latest()
            ->first();
    }

    public function updateCanteen(Canteen $canteen, array $data)
    {
        return $canteen->update($data);
    }

    public function findPurchaseTransactionWithRelations(int $transactionId)
    {
        return Transaction::with([
            'user:id,name,batch,email,nis,schoolclass_id',
            'user.schoolClass:id,class_name',
            'rfidCard:id,card_uid',
            'canteen:id,initial_balance,current_balance,opened_at,opened_by',
            'canteen.opener:id,name'
        ])->find($transactionId);
    }

    public function getCanteenTransactionHistory(
        ?string $type,
        ?string $status,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage,
        string $timezone = 'Asia/Jakarta'
    ): LengthAwarePaginator {
        $query = Transaction::query()
            ->with([
                'user:id,name,nis,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $this->applyDateFilters($query, $startDate, $endDate, $specificDate, $range, $timezone);

        return $query->paginate($perPage);
    }

    public function getPersonalTransactionHistory(
        int $userId,
        ?string $type,
        ?string $status,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage,
        string $timezone = 'Asia/Jakarta'
    ) {
        $query = Transaction::where('user_id', $userId)
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $this->applyDateFilters($query, $startDate, $endDate, $specificDate, $range, $timezone);

        return $query->paginate($perPage);
    }

    public function findSuccessfulPurchaseTransaction(int $transactionId)
    {
        return Transaction::where('id', $transactionId)
            ->where('type', 'pembelian')
            ->where('status', 'berhasil')
            ->with(['user', 'rfidCard', 'canteen'])
            ->first();
    }

    public function findExistingRefund(int $originalTransactionId)
    {
        return Transaction::where('type', 'refund')
            ->where('note', 'like', "%Refund untuk transaksi ID: $originalTransactionId%")
            ->first();
    }

    public function findRefundTransaction(int $refundTransactionId)
    {
        return Transaction::where('id', $refundTransactionId)
            ->where('type', 'refund')
            ->where('status', 'berhasil')
            ->with([
                'user:id,name,email,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,closed_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->first();
    }

    public function findOriginalTransaction(int $originalTransactionId)
    {
        return Transaction::where('id', $originalTransactionId)
            ->where('type', 'pembelian')
            ->with([
                'user:id,name,',
                'rfidCard:id,card_uid',
                'canteen:id,opened_by',
                'canteen.opener:id,name'
            ])
            ->first();
    }

    public function findCanteenById(int $canteenId)
    {
        return Canteen::find($canteenId);
    }

    public function findPendingWithdrawalForCanteen(int $canteenId)
    {
        return Transaction::where('canteen_id', $canteenId)
            ->where('type', 'pencairan')
            ->where('status', 'menunggu')
            ->first();
    }

    public function findPendingWithdrawalRequest(int $requestId)
    {
        return Transaction::where('id', $requestId)
            ->where('type', 'pencairan')
            ->where('status', 'menunggu')
            ->with('canteen')
            ->first();
    }

    public function updateTransaction(Transaction $transaction, array $data)
    {
        return $transaction->update($data);
    }

    public function findWithdrawalTransaction(int $withdrawalId)
    {
        return Transaction::where('id', $withdrawalId)
            ->where('type', 'pencairan')
            ->with([
                'user',
                'user.schoolClass',
                'canteen',
                'canteen.opener'
            ])
            ->first();
    }

    public function getPendingWithdrawalRequests(int $perPage)
    {
        return Transaction::where('type', 'pencairan')
            ->where('status', 'menunggu')
            ->with([
                'user:id,name',
                'canteen:id,initial_balance,current_balance,opened_at,closed_at'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getWithdrawalHistory(
        ?string $status,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage,
        string $timezone = 'Asia/Jakarta'
    ): LengthAwarePaginator {
        $query = Transaction::where('type', 'pencairan')
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'canteen:id,initial_balance,current_balance,opened_at,closed_at,opened_by',
                'canteen.opener:id,name',
            ])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $this->applyDateFilters($query, $startDate, $endDate, $specificDate, $range, $timezone);

        return $query->paginate($perPage);
    }

    public function findUserById(int $userId)
    {
        return User::find($userId);
    }

    private function applyDateFilters(
        $query,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        string $timezone
    ): void {
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