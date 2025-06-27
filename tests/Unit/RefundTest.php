<?php

use App\Models\Canteen;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\TransactionRepository;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->transactionRepository = Mockery::mock(TransactionRepository::class);
    $this->service = new TransactionService($this->transactionRepository);

    // 1. Mock query builder dulu
    $mockQueryBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $mockQueryBuilder->shouldReceive('from')->andReturnSelf();
    $mockQueryBuilder->shouldReceive('getConnection')->andReturnUsing(function () use (&$mockConnection) {
        return $mockConnection;
    });
    $mockQueryBuilder->shouldReceive('insertGetId')->andReturn(101); // ID dummy

    // 2. Baru buat grammar dan connection
    $mockGrammar = Mockery::mock(\Illuminate\Database\Query\Grammars\Grammar::class);
    $mockGrammar->shouldReceive('getDateFormat')->andReturn('Y-m-d H:i:s');

    $mockConnection = Mockery::mock(\Illuminate\Database\Connection::class);
    $mockConnection->shouldReceive('query')->andReturn($mockQueryBuilder);
    $mockConnection->shouldReceive('getName')->andReturn('testing');
    $mockConnection->shouldReceive('getQueryGrammar')->andReturn($mockGrammar);

    // 3. Resolver
    $mockResolver = Mockery::mock(\Illuminate\Database\ConnectionResolverInterface::class);
    $mockResolver->shouldReceive('connection')->andReturn($mockConnection);

    \Illuminate\Database\Eloquent\Model::setConnectionResolver($mockResolver);
});


afterEach(function () {
    Mockery::close();
});

describe('Refund', function () {
    it('should return success when refund is valid', function () {
        $transactionId = 3;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $userMock = Mockery::mock(User::class)->makePartial();
        $userMock->id = 1;
        $userMock->name = 'John Doe';
        $userMock->shouldReceive('only')->with(['id', 'name'])->andReturn([
            'id' => 1,
            'name' => 'John Doe',
        ]);

        $originalTransaction = (object) [
            'id' => 3,
            'user_id' => 1,
            'rfid_card_id' => 1,
            'canteen_id' => 1,
            'amount' => 50000,
            'user' => $userMock,
        ];

        $canteen = Mockery::mock(Canteen::class)->makePartial();
        $canteen->id = 1;
        $canteen->current_balance = 100000;

        $wallet = Mockery::mock(Wallet::class)->makePartial();
        $wallet->id = 1;
        $wallet->balance = 25000;

        $refundTransaction = (object) [
            'id' => 101,
            'created_at' => now()
        ];

        $this->transactionRepository
            ->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->once()
            ->andReturn($originalTransaction);

        $this->transactionRepository
            ->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->once()
            ->andReturn(null);

        $this->transactionRepository
            ->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->once()
            ->andReturn($canteen);

        $this->transactionRepository
            ->shouldReceive('findWalletByUserId')
            ->with($originalTransaction->user_id, true)
            ->once()
            ->andReturn($wallet);

        $this->transactionRepository
            ->shouldReceive('updateWallet')
            ->with($wallet, ['balance' => 75000])
            ->once();

        $this->transactionRepository
            ->shouldReceive('updateCanteen')
            ->with($canteen, ['current_balance' => 50000])
            ->once();

        $this->transactionRepository
            ->shouldReceive('createTransaction')
            ->with([
                'user_id' => 1,
                'rfid_card_id' => 1,
                'canteen_id' => 1,
                'type' => 'refund',
                'status' => 'berhasil',
                'amount' => 50000,
                'note' => 'Refund untuk transaksi ID: 3 - Salah input harga'
            ])
            ->once()
            ->andReturn($refundTransaction);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();
        DB::shouldReceive('rollback')->never();

        $result = $this->service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);

        expect($result['status'])->toBe('success');
        expect($result['message'])->toBe('Refund transaksi berhasil dilakukan.');
    });

    it('should return error when transaction ID is invalid', function () {
        $transactionId = 102;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $this->transactionRepository
            ->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->once()
            ->andReturn(null);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $result = $this->service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Transaksi pembelian tidak ditemukan atau sudah di-refund.');
    });

    it('should return error when transaction has already been refunded', function () {
        $transactionId = 3;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $originalTransaction = (object) [
            'id' => 3,
            'user_id' => 1,
            'canteen_id' => 1,
            'amount' => 50000
        ];

        $existingRefund = (object) [
            'id' => 99,
            'original_transaction_id' => 3
        ];

        $this->transactionRepository
            ->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->once()
            ->andReturn($originalTransaction);

        $this->transactionRepository
            ->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->once()
            ->andReturn($existingRefund);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $result = $this->service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Transaksi ini sudah pernah di-refund.');
    });

    it('should return error when refund is attempted from different canteen', function () {
        $transactionId = 3;
        $canteenOpenerId = 7;
        $note = "Salah input harga";

        $originalTransaction = (object) [
            'id' => 3,
            'user_id' => 1,
            'canteen_id' => 1,
            'amount' => 50000
        ];

        $canteen = (object) [
            'id' => 7,
            'current_balance' => 100000
        ];

        $this->transactionRepository
            ->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->once()
            ->andReturn($originalTransaction);

        $this->transactionRepository
            ->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->once()
            ->andReturn(null);

        $this->transactionRepository
            ->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->once()
            ->andReturn($canteen);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $result = $this->service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Refund hanya dapat dilakukan di kantin tempat transaksi asli.');
    });

    it('should return error when canteen balance is insufficient', function () {
        $transactionId = 3;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $originalTransaction = (object) [
            'id' => 3,
            'user_id' => 1,
            'rfid_card_id' => 1,
            'canteen_id' => 1,
            'amount' => 50000
        ];

        $canteen = (object) [
            'id' => 1,
            'current_balance' => 25000
        ];

        $this->transactionRepository
            ->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->once()
            ->andReturn($originalTransaction);

        $this->transactionRepository
            ->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->once()
            ->andReturn(null);

        $this->transactionRepository
            ->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->once()
            ->andReturn($canteen);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();

        $result = $this->service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Saldo kantin tidak mencukupi untuk melakukan refund.');
    });
});
