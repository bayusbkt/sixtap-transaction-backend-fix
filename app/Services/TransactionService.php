<?php

namespace App\Services;

use App\Helpers\HandleEmailNotification;
use App\Helpers\LogFailedTransaction;
use App\Models\Absence;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;


class TransactionService
{
    public function handleTopUp(string $cardUid, int $amount): array
    {
        try {
            DB::beginTransaction();

            $card = RfidCard::where('card_uid', $cardUid) ->where('is_active', true)->first();

            if (!$card) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak ditemukan atau tidak aktif.',
                    'code' => 404
                ];
            }

            $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

            if (!$wallet) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $amount;

            $wallet->update([
                'balance' => $newBalance,
                'last_top_up' => now()
            ]);

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'berhasil',
                'amount' => $amount,

            ]);

            $dataCard = $card->load('user');

            DB::commit();

            HandleEmailNotification::topUp($dataCard->user, $amount, $newBalance, $transaction->id);

            return [
                'status' => 'success',
                'message' => 'Top up berhasil.',
                'code' => 200,
                'data' => $dataCard,
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $card->user_id ?? null;
            $cardId = $card->id ?? null;

            LogFailedTransaction::format(
                $userId,
                $cardId,
                null,
                $amount,
                'top up',
                'Kesalahan pada server.'
            );

            return [
                'status' => 'error',
                'message' => 'Top up gagal.',
                'code' => 500,
            ];
        }
    }

    public function getTopUpDetail(int $transactionId): array
    {
        try {
            $transaction = Transaction::where('id', $transactionId)
                ->where('type', 'top up')
                ->where('status', 'berhasil')
                ->with([
                    'user:id,name,email,batch,schoolclass_id',
                    'user.schoolClass:id,class_name',
                    'rfidCard:id,card_uid',
                ])
                ->first();

            if (!$transaction) {
                return [
                    'status' => 'error',
                    'message' => 'Transaksi top up tidak ditemukan.',
                    'code' => 404
                ];
            }

            $wallet = Wallet::where('user_id', $transaction->user_id)->first();

            if (!$wallet) {
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $walletBalanceBeforeTopUp = $wallet ?
                $wallet->balance - $transaction->amount : 0;

            return [
                'status' => 'success',
                'message' => 'Detail top up berhasil didapatkan.',
                'code' => 200,
                'data' => [
                    'top_up_info' => [
                        'top_up_transaction_id' => $transaction->id,
                        'top_up_amount' => $transaction->amount,
                        'top_up_date' => $transaction->created_at,
                    ],
                    'user_info' => [
                        'id' => $transaction->user->id,
                        'name' => $transaction->user->name,
                        'email' => $transaction->user->email ?? null,
                        'batch' => $transaction->user->batch,
                        'class' => $transaction->user->schoolClass->class_name ?? null,
                        'rfid_card_uid' => $transaction->rfidCard->card_uid ?? null
                    ],
                    'wallet_info' => [
                        'balance_before_top_up' => $walletBalanceBeforeTopUp,
                        'balance_after_top_up' => $wallet->balance ?? 0,
                    ],
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil detail top up.',
                'code' => 500
            ];
        }
    }

    public function getTopUpHistory(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $query = Transaction::where('type', 'top up')
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid'
            ])
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

        $topUpHistory = $query->paginate($perPage);


        if ($topUpHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat top up.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat top up berhasil didapatkan.',
            'code' => 200,
            'data' => $topUpHistory
        ];
    }

    private function validateTransaction(string $cardUid, int $amount, int $canteenOpenerId): array
    {
        try {
            $card = RfidCard::where('card_uid', $cardUid)->first();

            if (!$card || !$card->user_id) {
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak ditemukan atau tidak terhubung dengan pengguna.',
                    'code' => 404
                ];
            }

            if (!$card->is_active) {
                return [
                    'status' => 'error',
                    'message' => 'Kartu tidak aktif.',
                    'code' => 422
                ];
            }

            $today = now()->format('Y-m-d');
            $todayName = now()->format('l');

            $absence = Absence::where('user_id', $card->user_id)
                ->where('day', $todayName)
                ->whereDate('time_in', $today)
                ->first();

            if (!$absence) {
                return [
                    'status' => 'error',
                    'message' => 'Siswa belum melakukan absensi hari ini.',
                    'code' => 422
                ];
            }

            if (!$absence->time_in) {
                return [
                    'status' => 'error',
                    'message' => 'Siswa belum melakukan absensi masuk.',
                    'code' => 422
                ];
            }

            if ($absence->time_out) {
                return [
                    'status' => 'error',
                    'message' => 'Siswa sudah melakukan absensi keluar. Transaksi tidak dapat dilakukan.',
                    'code' => 422
                ];
            }

            $canteen = Canteen::where('opened_by', $canteenOpenerId)
                ->whereNotNull('opened_at')
                ->whereNull('closed_at')
                ->latest()
                ->first();

            if (!$canteen) {
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada kantin yang sedang dibuka oleh pengguna ini.',
                    'code' => 404
                ];
            }

            if ($canteen->opened_at == null || $canteen->opened_at > now()) {
                return [
                    'status' => 'error',
                    'message' => 'Kantin belum dibuka.',
                    'code' => 422
                ];
            }

            $wallet = Wallet::where('user_id', $card->user_id)->first();

            if (!$wallet) {
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            if ($wallet->balance < $amount) {
                return [
                    'status' => 'error',
                    'message' => 'Saldo tidak mencukupi.',
                    'code' => 422
                ];
            }

            return [
                'status' => 'success',
                'message' => 'Validasi berhasil.',
                'code' => 200,
                'data' => [
                    'canteen_id' => $canteen->id,

                ]
            ];
        } catch (\Exception $e) {

            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat validasi.',
                'code' => 500,
            ];
        }
    }

    public function handlePurchase(string $cardUid, int $amount, int $canteenOpenerId, ?string $pin = null): array
    {
        $validation = $this->validateTransaction($cardUid, $amount, $canteenOpenerId);

        if ($validation['status'] !== 'success') {
            return $validation;
        }

        $canteenId = $validation['data']['canteen_id'];

        try {
            DB::beginTransaction();

            $card = RfidCard::where('card_uid', $cardUid)->with('user')->first();

            $canteen = Canteen::find($canteenId);

            if ($amount > 20000) {
                if (!$pin) {
                    DB::rollback();
                    return [
                        'status' => 'error',
                        'message' => 'PIN diperlukan untuk transaksi di atas Rp 20.000.',
                        'code' => 422
                    ];
                }

                if (!Hash::check($pin, $card->user->pin)) {
                    DB::rollback();

                    LogFailedTransaction::format(
                        $card->user_id,
                        $card->id,
                        $canteen->id,
                        $amount,
                        'pembelian',
                        'PIN tidak valid.'
                    );

                    return [
                        'status' => 'error',
                        'message' => 'PIN tidak valid.',
                        'code' => 422
                    ];
                }
            }

            $wallet = Wallet::where('user_id', $card->user_id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                DB::rollback();

                LogFailedTransaction::format(
                    $card->user_id,
                    $card->id,
                    $canteen->id,
                    $amount,
                    'pembelian',
                    'Saldo tidak mencukupi.'
                );

                return [
                    'status' => 'error',
                    'message' => 'Saldo tidak mencukupi.',
                    'code' => 422
                ];
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance - $amount;

            $wallet->update([
                'balance' => $newBalance
            ]);

            $canteen->update([
                'current_balance' => $canteen->current_balance + $amount
            ]);

            $canteenOpenerName = $canteen->opener->name;

            $transaction = Transaction::create([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => $canteen->id,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount,
            ]);

            DB::commit();

            HandleEmailNotification::purchase($card->user, $amount, $newBalance, $transaction->id, $canteenOpenerName);

            return [
                'status' => 'success',
                'message' => 'Transaksi pembelian berhasil dilakukan.',
                'code' => 200,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'user' => $card->user->only(['id', 'name']),
                    'amount' => $amount,
                    'canteen_id' => $canteen->id,
                    'timestamp' => $transaction->created_at
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $card->user_id ?? null;
            $cardId = $card->id ?? null;
            $canteenId = $canteen->id ?? null;

            if ($userId && $cardId && $canteenId) {
                LogFailedTransaction::format(
                    $userId,
                    $cardId,
                    $canteenId,
                    $amount,
                    'pembelian',
                    'Kesalahan pada server.'
                );
            }

            return [
                'status' => 'error',
                'message' => 'Transaksi pembelian gagal.',
                'code' => 500,
            ];
        }
    }

    public function getPurchaseDetail(int $userId, int $transactionId): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Pengguna tidak ditemukan.',
                'code' => 404
            ];
        }

        $transaction = Transaction::with([
            'user:id,name,batch,schoolclass_id',
            'user.schoolClass:id,class_name',
            'rfidCard:id,card_uid',
            'canteen:id,initial_balance,current_balance,opened_at,opened_by',
            'canteen.opener:id,name'
        ])->find($transactionId);

        if (!$transaction) {
            return [
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.',
                'code' => 404
            ];
        }

        $isStudent = strtolower($user->role->role_name) === 'siswa';

        if ($isStudent && $transaction->user_id !== $userId) {
            return [
                'status' => 'error',
                'message' => 'Anda tidak memiliki akses ke transaksi ini.',
                'code' => 403
            ];
        }

        if ($isStudent) {
            $wallet = Wallet::where('user_id', $userId)->first();

            if (!$wallet) {
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $walletBalanceBeforePurchase = $wallet ? $wallet->balance + $transaction->amount : 0;
        }



        $response = [
            'status' => 'success',
            'message' => 'Detail pembelian berhasil didapatkan',
            'code' => 200,
            'data' => [
                'purchase_info' => [
                    'purchase_transaction_id' => $transaction->id,
                    'purchase_amount' => (int) $transaction->amount,
                    'purchase_date' => $transaction->created_at,
                ],
                'user_info' => [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'email' => $transaction->user->email,
                    'batch' => $transaction->user->batch,
                    'class' => $transaction->user->schoolClass->class_name,
                    'rfid_card_uid' => $transaction->rfidCard->card_uid
                ],
            ]
        ];

        if ($isStudent) {
            $response['data']['wallet_info'] = [
                'balance_before_purchase' => $walletBalanceBeforePurchase,
                'balance_after_purchase' => (int) $wallet->balance,
            ];
        }

        if (!$isStudent) {
            $response['data']['canteen_info'] = [
                'canteen_id' => $transaction->canteen->id,
                'canteen_session' => [
                    'opened_at' => $transaction->canteen->opened_at,
                    'closed_at' => $transaction->canteen->closed_at ?? null,
                    'opened_by' => [
                        'id' => $transaction->canteen->opener->id ?? null,
                        'name' => $transaction->canteen->opener->name ?? null,
                    ]
                ]
            ];
            $response['data']['canteen_balance_info'] = [
                'initial_balance' => (int) $transaction->canteen->initial_balance,
                'current_balance' => (int) $transaction->canteen->current_balance
            ];
        }

        return $response;
    }

    public function getCanteenTransactionHistory(
        ?string $type,
        ?string $status,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage
    ): array {
        $validTypes = ['pembelian', 'refund', 'pencairan'];
        $validStatus = ['berhasil', 'menunggu', 'gagal'];

        $query = Transaction::query()
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type && in_array($type, $validTypes)) {
            $query->where('type', $type);
        } elseif ($type) {
            return [
                'status' => 'error',
                'message' => 'Tipe transaksi tidak valid. Hanya pembelian, refund, dan pencairan yang diizinkan.',
                'code' => 400
            ];
        }

        if ($status && in_array($status, $validStatus)) {
            $query->where('status', $status);
        } elseif ($status) {
            return [
                'status' => 'error',
                'message' => 'Tipe status transaksi tidak valid. Hanya berhasil, menunggu, dan gagal yang diizinkan.',
                'code' => 400
            ];
        }

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

        $transactionHistory = $query->paginate($perPage);

        if ($transactionHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat transaksi.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat transaksi berhasil didapatkan.',
            'code' => 200,
            'data' => $transactionHistory
        ];
    }

    public function getPersonalTransactionHistory(
        ?string $type,
        ?string $status,
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range,
        int $perPage,
        int $userId
    ): array {
        $validTypes = ['top up', 'pembelian', 'refund'];
        $validStatus = ['berhasil', 'gagal'];

        $query = Transaction::where('user_id', $userId)
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'rfidCard:id,card_uid',
                'canteen:id,initial_balance,current_balance,opened_at,opened_by',
                'canteen.opener:id,name'
            ])
            ->orderBy('created_at', 'desc');

        if ($type && in_array($type, $validTypes)) {
            $query->where('type', $type);
        } elseif ($type) {
            return [
                'status' => 'error',
                'message' => 'Tipe transaksi tidak valid. Hanya top up, pembelian dan refund yang diizinkan.',
                'code' => 400
            ];
        }

        if ($status && in_array($status, $validStatus)) {
            $query->where('status', $status);
        } elseif ($status) {
            return [
                'status' => 'error',
                'message' => 'Tipe status transaksi tidak valid. Hanya berhasil atau gagal yang diizinkan.',
                'code' => 400
            ];
        }

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

        $transactionHistory = $query->paginate($perPage);

        if ($transactionHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat transaksi.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat transaksi berhasil didapatkan.',
            'code' => 200,
            'data' => $transactionHistory
        ];
    }

    public function handleRefundTransaction(int $transactionId, int $canteenOpenerId, string $note): array
    {

        try {
            DB::beginTransaction();

            $originalTransaction = Transaction::where('id', $transactionId)
                ->where('type', 'pembelian')
                ->where('status', 'berhasil')
                ->with(['user', 'rfidCard', 'canteen'])
                ->first();

            if (!$originalTransaction) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Transaksi pembelian tidak ditemukan atau sudah di-refund.',
                    'code' => 404
                ];
            }

            $existingRefund = Transaction::where('type', 'refund')
                ->where('note', 'like', "%Refund untuk transaksi ID: $transactionId%")
                ->first();

            if ($existingRefund) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Transaksi ini sudah pernah di-refund.',
                    'code' => 422
                ];
            }

            $canteen = Canteen::where('opened_by', $canteenOpenerId)
                ->whereNotNull('opened_at')
                ->whereNull('closed_at')
                ->latest()
                ->first();

            if (!$canteen) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada kantin yang sedang dibuka oleh pengguna ini.',
                    'code' => 404
                ];
            }

            if ($originalTransaction->canteen_id !== $canteen->id) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Refund hanya dapat dilakukan di kantin tempat transaksi asli.',
                    'code' => 422
                ];
            }

            if ($canteen->current_balance < $originalTransaction->amount) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Saldo kantin tidak mencukupi untuk melakukan refund.',
                    'code' => 422
                ];
            }

            $wallet = Wallet::where('user_id', $originalTransaction->user_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $originalTransaction->amount;

            $wallet->update([
                'balance' => $newBalance
            ]);

            $canteen->update([
                'current_balance' => $canteen->current_balance - $originalTransaction->amount
            ]);

            $refundTransaction = Transaction::create([
                'user_id' => $originalTransaction->user_id,
                'rfid_card_id' => $originalTransaction->rfid_card_id,
                'canteen_id' => $canteen->id,
                'type' => 'refund',
                'status' => 'berhasil',
                'amount' => $originalTransaction->amount,
                'note' => 'Refund untuk transaksi ID: ' . $transactionId . ' - ' . $note
            ]);

            DB::commit();

            HandleEmailNotification::refund(
                $originalTransaction->user,
                $originalTransaction->amount,
                $newBalance,
                $refundTransaction->id,
                $transactionId,
                $note
            );

            return [
                'status' => 'success',
                'message' => 'Refund transaksi berhasil dilakukan.',
                'code' => 200,
                'data' => [
                    'refund_transaction_id' => $refundTransaction->id,
                    'original_transaction_id' => $originalTransaction->id,
                    'user' => $originalTransaction->user->only(['id', 'name']),
                    'refund_amount' => $originalTransaction->amount,
                    'canteen_id' => $canteen->id,
                    'timestamp' => $refundTransaction->created_at,
                    'note' => $note
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $originalTransaction->user_id ?? null;
            $cardId = $originalTransaction->rfid_card_id ?? null;
            $canteenId = $canteen->id ?? null;
            $amount = $originalTransaction->amount ?? 0;

            LogFailedTransaction::format(
                $userId,
                $cardId,
                $canteenId,
                $amount,
                'refund',
                'Kesalahan pada server saat melakukan refund.'
            );

            return [
                'status' => 'error',
                'message' => 'Refund transaksi gagal.',
                'code' => 500,
            ];
        }
    }

    public function getRefundDetail(int $userId, int $refundTransactionId): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'Pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $refundTransaction = Transaction::where('id', $refundTransactionId)
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

            if (!$refundTransaction) {
                return [
                    'status' => 'error',
                    'message' => 'Transaksi refund tidak ditemukan.',
                    'code' => 404
                ];
            }

            $originalTransactionId = null;
            if (preg_match('/Refund untuk transaksi ID: (\d+)/', $refundTransaction->note, $matches)) {
                $originalTransactionId = (int)$matches[1];
            }

            $originalTransaction = null;
            if ($originalTransactionId) {
                $originalTransaction = Transaction::where('id', $originalTransactionId)
                    ->where('type', 'pembelian')
                    ->with([
                        'user:id,name,',
                        'rfidCard:id,card_uid',
                        'canteen:id,opened_by',
                        'canteen.opener:id,name'
                    ])
                    ->first();
            }

            $isStudent = strtolower($user->role->role_name) === 'siswa';

            if ($isStudent && $refundTransaction->user_id !== $userId) {
                return [
                    'status' => 'error',
                    'message' => 'Anda tidak memiliki akses ke transaksi ini.',
                    'code' => 403
                ];
            }

            $walletAfterRefund = Wallet::where('user_id', $refundTransaction->user_id)->first();

            if (!$walletAfterRefund) {
                return [
                    'status' => 'error',
                    'message' => 'Wallet pengguna tidak ditemukan.',
                    'code' => 404
                ];
            }

            $walletBalanceBeforeRefund = $walletAfterRefund ?
                $walletAfterRefund->balance - $refundTransaction->amount : 0;

            $customNote = '';
            if (strpos($refundTransaction->note, ' - ') !== false) {
                $parts = explode(' - ', $refundTransaction->note, 2);
                $customNote = $parts[1] ?? '';
            }

            $response = [
                'status' => 'success',
                'message' => 'Detail refund berhasil didapatkan.',
                'code' => 200,
                'data' => [
                    'refund_info' => [
                        'refund_transaction_id' => $refundTransaction->id,
                        'original_transaction_id' => $originalTransactionId,
                        'refund_amount' => $refundTransaction->amount,
                        'refund_date' => $refundTransaction->created_at,
                        'custom_note' => $customNote,
                        'full_note' => $refundTransaction->note
                    ],
                    'user_info' => [
                        'id' => $refundTransaction->user->id,
                        'name' => $refundTransaction->user->name,
                        'email' => $refundTransaction->user->email ?? null,
                        'batch' => $refundTransaction->user->batch,
                        'class' => $refundTransaction->user->schoolClass->class_name ?? null,
                        'rfid_card_uid' => $refundTransaction->rfidCard->card_uid ?? null
                    ],
                    'wallet_info' => [
                        'balance_before_refund' => $walletBalanceBeforeRefund,
                        'balance_after_refund' => $walletAfterRefund->balance ?? 0,
                    ],
                    'original_transaction' => [
                        'transaction_id' => $originalTransaction->id,
                        'purchase_date' => $originalTransaction->created_at,
                        'amount' => $originalTransaction->amount,
                        'canteen_session' => [
                            'opened_by' => [
                                'id' => $originalTransaction->canteen->opener->id ?? null,
                                'name' => $originalTransaction->canteen->opener->name ?? null
                            ]
                        ]
                    ]
                ]
            ];

            if (!$isStudent) {
                $response['data']['canteen_info'] = [
                    'canteen_id' => $refundTransaction->canteen->id,
                    'canteen_session' => [
                        'opened_at' => $refundTransaction->canteen->opened_at,
                        'closed_at' => $refundTransaction->canteen->closed_at ?? null,
                        'opened_by' => [
                            'id' => $refundTransaction->canteen->opener->id ?? null,
                            'name' => $refundTransaction->canteen->opener->name ?? null
                        ]
                    ]
                ];

                $response['data']['canteen_balance_info'] = [
                    'initial_balance' => $refundTransaction->canteen->initial_balance,
                    'current_balance' => $refundTransaction->canteen->current_balance
                ];
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil detail refund.',
                'code' => 500
            ];
        }
    }

    public function requestCanteenBalanceExchange(int $canteenId, int $amount): array
    {
        try {
            DB::beginTransaction();

            $canteen = Canteen::find($canteenId);

            if (!$canteen) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kantin tidak ditemukan.',
                    'code' => 404
                ];
            }

            if ($canteen->closed_at === null) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Kantin masih dalam status terbuka. Tutup kantin terlebih dahulu.',
                    'code' => 422
                ];
            }

            if ($canteen->current_balance < $amount) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Saldo kantin tidak mencukupi untuk pencairan saldo kantin.',
                    'code' => 422
                ];
            }

            if ($amount <= 0) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Jumlah pencairan harus lebih dari 0.',
                    'code' => 422
                ];
            }

            $existingRequest = Transaction::where('canteen_id', $canteen->id)
                ->where('type', 'pencairan')
                ->where('status', 'menunggu')
                ->first();

            if ($existingRequest) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Masih ada permintaan pencairan yang belum diproses untuk kantin ini.',
                    'code' => 422
                ];
            }

            $transaction = Transaction::create([
                'user_id' => $canteen->opened_by,
                'rfid_card_id' => 0,
                'canteen_id' => $canteen->id,
                'type' => 'pencairan',
                'status' => 'menunggu',
                'amount' => $amount,
                'note' => 'Permintaan pencairan saldo untuk kantin ID: ' . $canteen->id
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Permintaan pencairan berhasil diajukan. Menunggu persetujuan admin.',
                'code' => 200,
                'data' => [
                    'request_id' => $transaction->id,
                    'canteen_id' => $canteen->id,
                    'requested_amount' => $amount,
                    'canteen_balance' => (int) $canteen->current_balance,
                    'requested_by' => $canteen->opener->name,
                    'status' => 'menunggu',
                    'timestamp' => $transaction->created_at
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            $userId = $canteen->opened_by ?? null;
            $canteenId = $canteen->id ?? null;

            if ($userId && $canteenId) {
                LogFailedTransaction::format(
                    $userId,
                    0,
                    $canteenId,
                    $amount,
                    'pencairan',
                    'Kesalahan pada server saat mengajukan permintaan pencairan dana.'
                );
            }

            return [
                'status' => 'error',
                'message' => 'Permintaan pencairan saldo kantin gagal diajukan.',
                'code' => 500,
            ];
        }
    }

    public function approveCanteenBalanceExchange(int $requestId, int $adminId): array
    {
        try {
            DB::beginTransaction();

            $request = Transaction::where('id', $requestId)
                ->where('type', 'pencairan')
                ->where('status', 'menunggu')
                ->with('canteen')
                ->first();

            if (!$request) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Permintaan pencairan saldo kantin tidak ditemukan atau sudah diproses.',
                    'code' => 404
                ];
            }

            $canteen = $request->canteen;

            if ($canteen->current_balance < $request->amount) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Saldo kantin tidak mencukupi untuk pencairan.',
                    'code' => 422
                ];
            }

            $oldBalance = $canteen->current_balance;
            $newBalance = $oldBalance - $request->amount;

            $canteen->update([
                'current_balance' => $newBalance
            ]);

            $request->update([
                'status' => 'berhasil',
                'note' => $request->note . ' - Disetujui oleh admin ID: ' . $adminId
            ]);

            $withdrawalTransaction = Transaction::create([
                'user_id' => $request->user_id,
                'rfid_card_id' => 0,
                'canteen_id' => $canteen->id,
                'type' => 'pencairan',
                'status' => 'berhasil',
                'amount' => $request->amount,
                'note' => 'Pencairan saldo kantin ID: ' . $canteen->id . ' - Request ID: ' . $requestId
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Permintaan pencairan saldo berhasil disetujui.',
                'code' => 200,
                'data' => [
                    'withdrawal_transaction_id' => $withdrawalTransaction->id,
                    'request_id' => $request->id,
                    'canteen_id' => $canteen->id,
                    'withdrawal_amount' => (int) $request->amount,
                    'previous_balance' => (int) $oldBalance,
                    'current_balance' => (int) $newBalance,
                    'withdrawn_by' => $canteen->opener->name,
                    'approved_by_admin_id' => $adminId,
                    'timestamp' => now()
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            return [
                'status' => 'error',
                'message' => 'Persetujuan pencairan saldo kantin gagal.',
                'code' => 500,
            ];
        }
    }

    public function rejectCanteenBalanceExchange(int $requestId, int $adminId, string $rejectionReason): array
    {
        try {
            DB::beginTransaction();

            $request = Transaction::where('id', $requestId)
                ->where('type', 'pencairan')
                ->where('status', 'menunggu')
                ->with('canteen')
                ->first();

            if (!$request) {
                DB::rollback();
                return [
                    'status' => 'error',
                    'message' => 'Permintaan pencairan saldo kantin tidak ditemukan atau sudah diproses.',
                    'code' => 404
                ];
            }

            $request->update([
                'status' => 'gagal',
                'note' => $request->note . ' - Ditolak oleh admin ID: ' . $adminId . ' - Alasan: ' . $rejectionReason
            ]);

            DB::commit();

            return [
                'status' => 'success',
                'message' => 'Permintaan pencairan saldo kantin berhasil ditolak.',
                'code' => 200,
                'data' => [
                    'request_id' => $request->id,
                    'canteen_id' => $request->canteen->id,
                    'requested_amount' => (int) $request->amount,
                    'rejected_by_admin_id' => $adminId,
                    'rejection_reason' => $rejectionReason,
                    'timestamp' => now()
                ]
            ];
        } catch (\Exception $e) {
            DB::rollback();

            return [
                'status' => 'error',
                'message' => 'Penolakan pencairan saldo kantin gagal.',
                'code' => 500,
            ];
        }
    }

    public function getWithdrawalDetail(int $withdrawalId, bool $isStudent = false): array
    {
        try {
            $withdrawalTransaction = Transaction::where('id', $withdrawalId)
                ->where('type', 'pencairan')
                ->with([
                    'user',
                    'user.schoolClass',
                    'canteen',
                    'canteen.opener'
                ])
                ->first();

            if (!$withdrawalTransaction) {
                return [
                    'status' => 'error',
                    'message' => 'Transaksi pencairan tidak ditemukan.',
                    'code' => 404
                ];
            }

            $customNote = '';
            if (strpos($withdrawalTransaction->note, ' - ') !== false) {
                $parts = explode(' - ', $withdrawalTransaction->note, 2);
                $customNote = $parts[1] ?? '';
            }

            $response = [
                'status' => 'success',
                'message' => 'Detail pencairan berhasil didapatkan.',
                'code' => 200,
                'data' => [
                    'withdrawal_info' => [
                        'withdrawal_transaction_id' => $withdrawalTransaction->id,
                        'withdrawal_amount' => (int) $withdrawalTransaction->amount,
                        'withdrawal_date' => $withdrawalTransaction->created_at,
                        'status' => $withdrawalTransaction->status,
                        'custom_note' => $customNote,
                        'full_note' => $withdrawalTransaction->note
                    ],
                    'user_info' => [
                        'id' => $withdrawalTransaction->user->id,
                        'name' => $withdrawalTransaction->user->name,
                        'email' => $withdrawalTransaction->user->email ?? null,
                    ]
                ]
            ];

            if (!$isStudent) {
                $response['data']['canteen_info'] = [
                    'canteen_id' => $withdrawalTransaction->canteen->id,
                    'canteen_session' => [
                        'opened_at' => $withdrawalTransaction->canteen->opened_at,
                        'closed_at' => $withdrawalTransaction->canteen->closed_at ?? null,
                        'opened_by' => [
                            'id' => $withdrawalTransaction->canteen->opener->id ?? null,
                            'name' => $withdrawalTransaction->canteen->opener->name ?? null
                        ]
                    ]
                ];

                $response['data']['canteen_balance_info'] = [
                    'initial_balance' => (int) $withdrawalTransaction->canteen->initial_balance,
                    'current_balance' => (int) $withdrawalTransaction->canteen->current_balance
                ];
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil detail pencairan.',
                'code' => 500
            ];
        }
    }

    public function getPendingWithdrawalRequests(int $perPage): array
    {
        try {
            $pendingRequests = Transaction::where('type', 'pencairan')
                ->where('status', 'menunggu')
                ->with([
                    'user:id,name',
                    'canteen:id,initial_balance,current_balance,opened_at,closed_at'
                ])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            if ($pendingRequests->isEmpty()) {
                return [
                    'status' => 'error',
                    'message' => 'Tidak ada permintaan pencairan saldo kantin yang menunggu persetujuan.',
                    'code' => 404
                ];
            }

            return [
                'status' => 'success',
                'message' => 'Daftar permintaan pencairan saldo kantin berhasil didapatkan.',
                'code' => 200,
                'data' => $pendingRequests
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Gagal mengambil daftar permintaan pencairan saldo kantin.',
                'code' => 500,
            ];
        }
    }

    public function getWithdrawalHistory(
    ?string $status,
    ?string $startDate,
    ?string $endDate,
    ?string $specificDate,
    ?string $range,
    int $perPage
): array {
    $validStatus = ['berhasil', 'menunggu', 'gagal'];

    try {
        $query = Transaction::where('type', 'pencairan')
            ->with([
                'user:id,name,batch,schoolclass_id',
                'user.schoolClass:id,class_name',
                'canteen:id,initial_balance,current_balance,opened_at,closed_at,opened_by',
                'canteen.opener:id,name',
            ])
            ->orderBy('created_at', 'desc');

        if ($status && in_array($status, $validStatus)) {
            $query->where('status', $status);
        } elseif ($status) {
            return [
                'status' => 'error',
                'message' => 'Status tidak valid. Hanya berhasil, menunggu, dan gagal yang diizinkan.',
                'code' => 400
            ];
        }

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

        $withdrawalHistory = $query->paginate($perPage);

        if ($withdrawalHistory->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada riwayat pencairan saldo kantin.',
                'code' => 404
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Riwayat pencairan saldo kantin berhasil didapatkan.',
            'code' => 200,
            'data' => $withdrawalHistory
        ];

    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Gagal mengambil riwayat pencairan saldo kantin.',
            'code' => 500,
        ];
    }
}
    
}
