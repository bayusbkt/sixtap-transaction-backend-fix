<?php

namespace App\Services;

use App\Helpers\HandleEmailNotification;
use App\Helpers\HandleServiceResponse;
use App\Helpers\LogFailedTransaction;
use App\Repositories\TransactionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TransactionService
{
    protected $transactionRepository;

    public function __construct(TransactionRepository $transactionRepository)
    {
        $this->transactionRepository = $transactionRepository;
    }

    public function handleTopUp(string $cardUid, int $amount): array
    {
        try {
            DB::beginTransaction();

            $card = $this->transactionRepository->findRfidCardByUid($cardUid);

            if (!$card) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Kartu tidak ditemukan atau tidak aktif.',  404);
            }

            $wallet = $this->transactionRepository->findWalletByUserId($card->user_id, true);

            if (!$wallet) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.',  404);
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $amount;

            $this->transactionRepository->updateWallet($wallet, [
                'balance' => $newBalance,
                'last_top_up' => now()
            ]);

            $transaction = $this->transactionRepository->createTransaction([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => null,
                'type' => 'top up',
                'status' => 'berhasil',
                'amount' => $amount,
            ]);

            DB::commit();

            HandleEmailNotification::topUp($card->user, $amount, $newBalance, $transaction->id);

            return HandleServiceResponse::successResponse('Top up berhasil.', [$card], 200);
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

            return HandleServiceResponse::errorResponse('Terjadi kesalahan pada server saat memproses top up', 500);
        }
    }

    public function getTopUpDetail(int $transactionId): array
    {
        try {
            $transaction = $this->transactionRepository->findTopUpTransaction($transactionId);

            if (!$transaction) {
                return HandleServiceResponse::errorResponse('Transaksi top up tidak ditemukan.', 404);
            }

            $wallet = $this->transactionRepository->findWalletByUserId($transaction->user_id);

            if (!$wallet) {
                return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
            }

            $walletBalanceBeforeTopUp = $wallet ? $wallet->balance - $transaction->amount : 0;

            $responseData = [
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
            ];

            return HandleServiceResponse::successResponse('Detail top up berhasil didapatkan.', [$responseData], 200);
        } catch (\Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses detail top up.', 500);
        }
    }

    public function getTopUpHistory(
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

        $topUpHistory = $this->transactionRepository->getTopUpHistory(
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        if ($topUpHistory->isEmpty()) {
            return HandleServiceResponse::errorResponse('Tidak ada riwayat top up.', 404);
        }

        return HandleServiceResponse::successResponse('Riwayat top up berhasil didapatkan.', [$topUpHistory], 200);
    }

    private function validateTransaction(string $cardUid, int $amount, int $canteenOpenerId): array
    {
        try {
            $card = $this->transactionRepository->findRfidCardByUid($cardUid);

            if (!$card || !$card->user_id) {
                return HandleServiceResponse::errorResponse('Kartu tidak ditemukan atau tidak terhubung dengan pengguna.', 404);
            }

            if (!$card->is_active) {
                return HandleServiceResponse::errorResponse('Kartu tidak aktif.', 422);
            }

            $absence = $this->transactionRepository->findAbsenceForToday($card->user_id);

            if (!$absence) {
                return HandleServiceResponse::errorResponse('Siswa belum melakukan absensi hari ini.', 422);
            }

            if (!$absence->time_in) {
                return HandleServiceResponse::errorResponse('Siswa belum melakukan absensi masuk.', 422);
            }

            if ($absence->time_out) {
                return HandleServiceResponse::errorResponse('Siswa sudah melakukan absensi keluar. Transaksi tidak dapat dilakukan.', 422);
            }

            $canteen = $this->transactionRepository->findOpenCanteenByOpener($canteenOpenerId);
            if (!$canteen) {
                return HandleServiceResponse::errorResponse('Tidak ada kantin yang sedang dibuka oleh pengguna ini.', 404);
            }

            if ($canteen->opened_at == null || $canteen->opened_at > now()) {
                return HandleServiceResponse::errorResponse('Kantin belum dibuka.', 422);
            }

            $wallet = $this->transactionRepository->findWalletByUserId($card->user_id);
            if (!$wallet) {
                return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
            }

            if ($wallet->balance < $amount) {
                return HandleServiceResponse::errorResponse('Saldo tidak mencukupi.', 422);
            }

            return HandleServiceResponse::successResponse('Validasi transaksi pembelian berhasil.', ['canteen_id' => $canteen->id], 200);
        } catch (\Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat validasi transaksi pembelian.', 500);
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

            $card = $this->transactionRepository->findRfidCardByUidWithRelation($cardUid);
            $canteen = $this->transactionRepository->findCanteenById($canteenId);

            if ($amount > 20000) {
                if (!$pin) {
                    DB::rollback();
                    return HandleServiceResponse::errorResponse('PIN diperlukan untuk transaksi di atas Rp 20.000.', 422);
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

                    return HandleServiceResponse::errorResponse('PIN tidak valid.', 422);
                }
            }

            $wallet = $this->transactionRepository->findWalletByUserId($card->user_id, true);

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

                return HandleServiceResponse::errorResponse('Saldo tidak mencukupi.', 422);
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance - $amount;

            $this->transactionRepository->updateWallet($wallet, [
                'balance' => $newBalance
            ]);

            $this->transactionRepository->updateCanteen($canteen, [
                'current_balance' => $canteen->current_balance + $amount
            ]);

            $canteenOpenerName = $canteen->opener->name;

            $transaction = $this->transactionRepository->createTransaction([
                'user_id' => $card->user_id,
                'rfid_card_id' => $card->id,
                'canteen_id' => $canteen->id,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount,
            ]);

            $responseData = [
                'transaction_id' => $transaction->id,
                'user' => $card->user->only(['id', 'name']),
                'amount' => $amount,
                'canteen_id' => $canteen->id,
                'timestamp' => $transaction->created_at
            ];

            DB::commit();

            HandleEmailNotification::purchase($card->user, $amount, $newBalance, $transaction->id, $canteenOpenerName);

            return HandleServiceResponse::successResponse('Transaksi pembelian berhasil dilakukan.', [$responseData], 200);
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
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses transaksi pembelian.', 500);
        }
    }

    public function getPurchaseDetail(int $userId, int $transactionId): array
    {
        $user = $this->transactionRepository->findUserById($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        $transaction = $this->transactionRepository->findPurchaseTransactionWithRelations($transactionId);

        if (!$transaction) {
            return HandleServiceResponse::errorResponse('Transaksi pembelian tidak ditemukan.', 404);
        }

        $isStudent = strtolower($user->role->role_name) === 'siswa';

        if ($isStudent && $transaction->user_id !== $userId) {
            return HandleServiceResponse::errorResponse('Anda tidak memiliki akses ke transaksi ini.', 403);
        }

        if ($isStudent) {
            $wallet = $this->transactionRepository->findWalletByUserId($userId);

            if (!$wallet) {
                return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
            }

            $walletBalanceBeforePurchase = $wallet ? $wallet->balance + $transaction->amount : 0;
        }

        $response = [
            'purchase_info' => [
                'purchase_transaction_id' => $transaction->id,
                'purchase_amount' => (int) $transaction->amount,
                'purchase_date' => $transaction->created_at,
            ],
            'user_info' => [
                'id' => $transaction->user->id,
                'name' => $transaction->user->name,
                'email' => $transaction->user->email,
                'nis' => $transaction->user->nis,
                'batch' => $transaction->user->batch,
                'class' => $transaction->user->schoolClass->class_name,
                'rfid_card_uid' => $transaction->rfidCard->card_uid
            ],
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
        return HandleServiceResponse::successResponse('Detail pembelian berhasil didapatkan.', [$response], 200);
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

        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        if ($type && !in_array($type, $validTypes)) {
            return HandleServiceResponse::errorResponse('Tipe transaksi tidak valid. Hanya pembelian, refund, dan pencairan yang diizinkan.', 400);
        }

        if ($status && !in_array($status, $validStatus)) {
            return HandleServiceResponse::errorResponse('Tipe status transaksi tidak valid. Hanya berhasil, menunggu, dan gagal yang diizinkan', 400);
        }

        $transactionHistory = $this->transactionRepository->getCanteenTransactionHistory(
            $type,
            $status,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        if ($transactionHistory->isEmpty()) {
            $typeMessage = match ($type) {
                'top up' => 'top up',
                'pembelian' => 'pembelian',
                'refund' => 'refund',
                'pencairan' => 'pencairan',
                default => 'transaksi',
            };

            return HandleServiceResponse::errorResponse('Tidak ada riwayat ' . $typeMessage . '.', 404);
        }


        $typeMessage = match ($type) {
            'top up' => 'top up',
            'pembelian' => 'pembelian',
            'refund' => 'refund',
            'pencairan' => 'pencairan',
            default => 'transaksi',
        };

        return HandleServiceResponse::successResponse('Riwayat ' . $typeMessage . ' berhasil didapatkan.', [$transactionHistory], 200);
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

        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        if ($type && !in_array($type, $validTypes)) {
            return HandleServiceResponse::errorResponse('Tipe transaksi tidak valid. Hanya top up, pembelian, dan refund yang diizinkan.', 400);
        }

        if ($status && !in_array($status, $validStatus)) {
            return HandleServiceResponse::errorResponse('Tipe status transaksi tidak valid. Hanya berhasil atau gagal yang diizinkan.', 400);
        }

        $transactionHistory = $this->transactionRepository->getPersonalTransactionHistory(
            $userId,
            $type,
            $status,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        if ($transactionHistory->isEmpty()) {
            $typeMessage = match ($type) {
                'top up' => 'top up',
                'pembelian' => 'pembelian',
                'refund' => 'refund',
                default => 'transaksi',
            };

            return HandleServiceResponse::errorResponse('Tidak ada riwayat ' . $typeMessage . '.', 404);
        }


        $typeMessage = match ($type) {
            'top up' => 'top up',
            'pembelian' => 'pembelian',
            'refund' => 'refund',
            default => 'transaksi',
        };

        return HandleServiceResponse::successResponse('Riwayat ' . $typeMessage . ' berhasil didapatkan.', [$transactionHistory], 200);
    }

    public function handleRefundTransaction(int $transactionId, int $canteenOpenerId, string $note): array
    {
        try {
            DB::beginTransaction();

            $originalTransaction = $this->transactionRepository->findSuccessfulPurchaseTransaction($transactionId);
            if (!$originalTransaction) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Transaksi pembelian tidak ditemukan atau sudah di-refund.', 404);
            }

            $existingRefund = $this->transactionRepository->findExistingRefund($transactionId);

            if ($existingRefund) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Transaksi ini sudah pernah di-refund.', 422);
            }

            $canteen = $this->transactionRepository->findOpenCanteenByOpener($canteenOpenerId);

            if (!$canteen) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Tidak ada kantin yang sedang dibuka oleh pengguna ini.', 404);
            }

            if ($originalTransaction->canteen_id !== $canteen->id) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Refund hanya dapat dilakukan di kantin tempat transaksi asli.', 422);
            }

            if ($canteen->current_balance < $originalTransaction->amount) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Saldo kantin tidak mencukupi untuk melakukan refund.', 422);
            }

            $wallet = $this->transactionRepository->findWalletByUserId($originalTransaction->user_id, true);

            if (!$wallet) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
            }

            $oldBalance = $wallet->balance;
            $newBalance = $oldBalance + $originalTransaction->amount;

            $this->transactionRepository->updateWallet($wallet, [
                'balance' => $newBalance
            ]);

            $this->transactionRepository->updateCanteen($canteen, [
                'current_balance' => $canteen->current_balance - $originalTransaction->amount
            ]);

            $refundTransaction = $this->transactionRepository->createTransaction([
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

            $responseData = [
                'refund_transaction_id' => $refundTransaction->id,
                'original_transaction_id' => $originalTransaction->id,
                'user' => $originalTransaction->user->only(['id', 'name']),
                'refund_amount' => $originalTransaction->amount,
                'canteen_id' => $canteen->id,
                'timestamp' => $refundTransaction->created_at,
                'note' => $note
            ];

            return HandleServiceResponse::successResponse('Refund transaksi berhasil dilakukan.', [$responseData], 200);
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

            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses refund.', 500);
        }
    }

    public function getRefundDetail(int $userId, int $refundTransactionId): array
    {
        $user = $this->transactionRepository->findUserById($userId);

        if (!$user) {
            return HandleServiceResponse::errorResponse('Pengguna tidak ditemukan.', 404);
        }

        $refundTransaction = $this->transactionRepository->findRefundTransaction($refundTransactionId);

        if (!$refundTransaction) {
            return HandleServiceResponse::errorResponse('Transaksi refund tidak ditemukan.', 404);
        }

        $originalTransactionId = null;
        if (preg_match('/Refund untuk transaksi ID: (\d+)/', $refundTransaction->note, $matches)) {
            $originalTransactionId = (int)$matches[1];
        }

        $originalTransaction = null;
        if ($originalTransactionId) {
            $originalTransaction = $this->transactionRepository->findOriginalTransaction($originalTransactionId);
        }

        $isStudent = strtolower($user->role->role_name) === 'siswa';
        if ($isStudent && $refundTransaction->user_id !== $userId) {
            return HandleServiceResponse::errorResponse('Anda tidak memiliki akses ke transaksi ini.', 403);
        }

        $walletAfterRefund = $this->transactionRepository->findWalletByUserId($refundTransaction->user_id);
        if (!$walletAfterRefund) {
            return HandleServiceResponse::errorResponse('Wallet pengguna tidak ditemukan.', 404);
        }

        $walletBalanceBeforeRefund = $walletAfterRefund ?
            $walletAfterRefund->balance - $refundTransaction->amount : 0;

        $customNote = '';
        if (strpos($refundTransaction->note, ' - ') !== false) {
            $parts = explode(' - ', $refundTransaction->note, 2);
            $customNote = $parts[1] ?? '';
        }

        $response = [
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

        return HandleServiceResponse::successResponse('Detail refund berhasil didapatkan.', [$response]);
    }

    public function requestCanteenBalanceExchange(int $canteenId, int $amount): array
    {
        try {
            DB::beginTransaction();

            $canteen = $this->transactionRepository->findCanteenById($canteenId);

            if (!$canteen) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Kantin tidak ditemukan.', 404);
            }

            if ($canteen->closed_at === null) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Kantin masih dalam status terbuka. Tutup Kantin terlebih dahulu.', 422);
            }

            if ($canteen->current_balance < $amount) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Saldo kantin tidak mencukupi untuk memproses pencairan.', 422);
            }

            if ($amount <= 0) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Jumlah pencairan harus lebih dari 0.', 422);
            }

            $existingRequest = $this->transactionRepository->findPendingWithdrawalForCanteen($canteen->id);

            if ($existingRequest) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Masih ada permintaan pencairan yang belum disetujui untuk kantin ini.', 422);
            }

            $transaction = $this->transactionRepository->createTransaction([
                'user_id' => $canteen->opened_by,
                'rfid_card_id' => 0,
                'canteen_id' => $canteen->id,
                'type' => 'pencairan',
                'status' => 'menunggu',
                'amount' => $amount,
                'note' => 'Permintaan pencairan saldo untuk kantin ID: ' . $canteen->id
            ]);

            DB::commit();

            $responseData = [
                'request_id' => $transaction->id,
                'canteen_id' => $canteen->id,
                'requested_amount' => $amount,
                'canteen_balance' => (int) $canteen->current_balance,
                'requested_by' => $canteen->opener->name,
                'status' => 'menunggu',
                'timestamp' => $transaction->created_at
            ];

            return HandleServiceResponse::successResponse('Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.', $responseData, 200);
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
                    'Kesalahan pada server saat mengajukan permintaan pencairan saldo kantin.'
                );
            }

            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses permintaan pencairan saldo kantin.', 500);
        }
    }

    public function approveCanteenBalanceExchange(int $requestId, int $adminId): array
    {
        try {
            DB::beginTransaction();

            $request = $this->transactionRepository->findPendingWithdrawalRequest($requestId);

            if (!$request) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Permintaan pencairan saldo kantin tidak ditemukan atau sudah diproses.', 404);
            }

            $canteen = $request->canteen;

            if ($canteen->current_balance < $request->amount) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Saldo kantin tidak mencukupi untuk pencairan.', 422);
            }

            $oldBalance = $canteen->current_balance;
            $newBalance = $oldBalance - $request->amount;

            $this->transactionRepository->updateCanteen($canteen, [
                'current_balance' => $newBalance
            ]);

            $this->transactionRepository->updateTransaction($request, [
                'status' => 'berhasil',
                'note' => $request->note . ' - Disetujui oleh admin ID: ' . $adminId
            ]);

            $withdrawalTransaction = $this->transactionRepository->createTransaction([
                'user_id' => $request->user_id,
                'rfid_card_id' => 0,
                'canteen_id' => $canteen->id,
                'type' => 'pencairan',
                'status' => 'berhasil',
                'amount' => $request->amount,
                'note' => 'Pencairan saldo kantin ID: ' . $canteen->id . ' - Request ID: ' . $requestId
            ]);

            DB::commit();

            $responseData = [
                'withdrawal_transaction_id' => $withdrawalTransaction->id,
                'request_id' => $request->id,
                'canteen_id' => $canteen->id,
                'withdrawal_amount' => (int) $request->amount,
                'previous_balance' => (int) $oldBalance,
                'current_balance' => (int) $newBalance,
                'withdrawn_by' => $canteen->opener->name,
                'approved_by_admin_id' => $adminId,
                'timestamp' => now()
            ];

            return HandleServiceResponse::successResponse('Permintaan pencairan saldo berhasil disetujui.', [$responseData], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses persetujuan pencairan saldo kantin.', 500);
        }
    }

    public function rejectCanteenBalanceExchange(int $requestId, int $adminId, string $rejectionReason): array
    {
        try {
            DB::beginTransaction();

            $request = $this->transactionRepository->findPendingWithdrawalRequest($requestId);

            if (!$request) {
                DB::rollback();
                return HandleServiceResponse::errorResponse('Permintaan pencairan saldo kantin tidak ditemukan atau sudah diproses.', 404);
            }

            $this->transactionRepository->updateTransaction($request, [
                'status' => 'gagal',
                'note' => $request->note . ' - Ditolak oleh admin ID: ' . $adminId . ' - Alasan: ' . $rejectionReason
            ]);

            DB::commit();

            $responseData = [
                'request_id' => $request->id,
                'canteen_id' => $request->canteen->id,
                'requested_amount' => (int) $request->amount,
                'rejected_by_admin_id' => $adminId,
                'rejection_reason' => $rejectionReason,
                'timestamp' => now()
            ];

            return HandleServiceResponse::successResponse('Permintaan pencairan saldo berhasil ditolak.', [$responseData]);
        } catch (\Exception $e) {
            DB::rollback();
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses penolakan pencairan saldo kantin', 500);
        }
    }

    public function getWithdrawalDetail(int $withdrawalId, bool $isStudent = false): array
    {
        try {
            $withdrawalTransaction = $this->transactionRepository->findWithdrawalTransaction($withdrawalId);

            if (!$withdrawalTransaction) {
                return HandleServiceResponse::errorResponse('Transaksi pencairan tidak ditemukan.', 404);
            }

            $customNote = '';
            if (strpos($withdrawalTransaction->note, ' - ') !== false) {
                $parts = explode(' - ', $withdrawalTransaction->note, 2);
                $customNote = $parts[1] ?? '';
            }

            $response = [

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

            return HandleServiceResponse::successResponse('Berhasil mendapatkan detail pencairan saldo kantin.', [$response]);
        } catch (\Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses detail pencairan saldo kantin.', 404);
        }
    }

    public function getPendingWithdrawalRequests(int $perPage): array
    {
        try {
            $pendingRequests = $this->transactionRepository->getPendingWithdrawalRequests($perPage);

            if ($pendingRequests->isEmpty()) {
                return HandleServiceResponse::errorResponse('Tidak ada permintaan pencairan saldo kantin yang menunggu persetujuan.', 404);
            }

            return HandleServiceResponse::successResponse('Daftar permintaan pencairan saldo kantin berhasil didapatkan.', [$pendingRequests], 200);
        } catch (\Exception $e) {
            return HandleServiceResponse::errorResponse('Terjadi kesalahan saat memproses daftar permintaan pencairan saldo kantin', 500);
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
        $validStatus = ['berhasil', 'gagal'];

        $validationResult = $this->validateDateParameters($startDate, $endDate, $specificDate, $range);
        if ($validationResult) {
            return $validationResult;
        }

        if ($status && !in_array($status, $validStatus)) {
            return HandleServiceResponse::errorResponse('Status tidak valid. Hanya berhasil dan gagal yang diizinkan.', 400);
        }

        $withdrawalHistory = $this->transactionRepository->getWithdrawalHistory(
            $status,
            $startDate,
            $endDate,
            $specificDate,
            $range,
            $perPage
        );

        if ($withdrawalHistory->isEmpty()) {
            return HandleServiceResponse::errorResponse('Tidak ada riwayat pencairan saldo kantin.', 404);
        }

        return HandleServiceResponse::successResponse('Riwayat pencairan berhasil didapatkan.', [$withdrawalHistory], 200);
    }

    private function validateDateParameters(
        ?string $startDate,
        ?string $endDate,
        ?string $specificDate,
        ?string $range
    ): ?array {
        if ($specificDate && !$this->transactionRepository->validateDateFormat($specificDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($startDate && !$this->transactionRepository->validateDateFormat($startDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($endDate && !$this->transactionRepository->validateDateFormat($endDate)) {
            return HandleServiceResponse::errorResponse('Format tanggal tidak valid. Gunakan format YYYY-MM-DD.', 400);
        }

        if ($startDate && $endDate && !$this->transactionRepository->validateDateRange($startDate, $endDate)) {
            return HandleServiceResponse::errorResponse('Tanggal mulai tidak boleh lebih besar dari tanggal akhir.', 400);
        }

        if ($range && !$this->transactionRepository->isValidRange($range)) {
            return HandleServiceResponse::errorResponse('Range tidak valid. Gunakan: harian, mingguan, bulanan, atau tahunan.', 400);
        }

        return null;
    }
}
