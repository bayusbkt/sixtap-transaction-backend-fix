<?php

use App\Models\Canteen;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use App\Services\TransactionService;
use App\Helpers\HandleServiceResponse;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->transactionRepository = Mockery::mock(TransactionRepository::class);
    $this->transactionService = new TransactionService($this->transactionRepository);
});

afterEach(function () {
    Mockery::close();
});

describe('requestCanteenBalanceExchange', function () {

    it('should return success when all condition is valid', function () {
        $canteenId = 1;
        $amount = 2500000;

        $opener = (object)[
            'id' => 1,
            'name' => 'John Doe',
        ];

        $canteen = (object)[
            'id' => 1,
            'opened_by' => 1,
            'current_balance' => 3000000,
            'closed_at' => now(),
            'opener' => $opener,
        ];

        $transaction = (object)[
            'id' => 101,
            'created_at' => now(),
        ];

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn($canteen);

        $this->transactionRepository
            ->shouldReceive('findPendingWithdrawalForCanteen')
            ->with($canteen->id)
            ->once()
            ->andReturn(null);

        $this->transactionRepository
            ->shouldReceive('createTransaction')
            ->with([
                'user_id' => $canteen->opened_by,
                'rfid_card_id' => 0,
                'canteen_id' => $canteen->id,
                'type' => 'pencairan',
                'status' => 'menunggu',
                'amount' => $amount,
                'note' => 'Permintaan pencairan saldo untuk kantin ID: ' . $canteen->id
            ])
            ->once()
            ->andReturn($transaction);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('success');
        expect($result['message'])->toBe('Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.');
    });

    it('should return error when canteen is not found', function () {
        $canteenId = 102;
        $amount = 2500000;

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn(null);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Kantin tidak ditemukan.');
    });

    it('should return error when canteen is still open', function () {
        $canteenId = 1;
        $amount = 2500000;

        $canteen = (object)[
            'id' => 1,
            'opened_by' => 1,
            'current_balance' => 3000000,
            'closed_at' => null,
        ];

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn($canteen);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Kantin masih dalam status terbuka. Tutup Kantin terlebih dahulu.');
    });

    it('should return error when balance is insufficient', function () {
        $canteenId = 1;
        $amount = 3000000;

        $canteen = (object)[
            'id' => 1,
            'opened_by' => 1,
            'current_balance' => 2500000,
            'closed_at' => now(),
        ];

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn($canteen);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Saldo kantin tidak mencukupi untuk memproses pencairan.');
    });

    it('should return error when amount is zero or less', function () {
        $canteenId = 1;
        $amount = 0;

        $canteen = (object)[
            'id' => 1,
            'opened_by' => 1,
            'current_balance' => 3000000,
            'closed_at' => now(),
        ];

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn($canteen);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Jumlah pencairan harus lebih dari 0.');
    });

    it('should return error when a withdrawal request already exists', function () {
        $canteenId = 1;
        $amount = 2500000;

        $canteen = (object)[
            'id' => 1,
            'opened_by' => 1,
            'current_balance' => 3000000,
            'closed_at' => now(),
        ];

        $existingRequest = (object)[
            'id' => 100,
            'status' => 'menunggu',
        ];

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $this->transactionRepository
            ->shouldReceive('findCanteenById')
            ->with($canteenId)
            ->once()
            ->andReturn($canteen);

        $this->transactionRepository
            ->shouldReceive('findPendingWithdrawalForCanteen')
            ->with($canteen->id)
            ->once()
            ->andReturn($existingRequest);

        $result = $this->transactionService->requestCanteenBalanceExchange($canteenId, $amount);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Masih ada permintaan pencairan yang belum disetujui untuk kantin ini.');
    });
});
