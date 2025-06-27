<?php

use App\Models\RfidCard;
use App\Models\Transaction;
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

describe('Handle top up', function () {
    it('should return success when card uid and amount are valid', function () {
        $cardUid = 'ABC123EFG';
        $amount = 50000;
        $oldBalance = 4000;
        $newBalance = 54000;

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->name = 'Test User';

        $card = Mockery::mock(RfidCard::class)->makePartial();
        $card->id = 1;
        $card->card_uid = $cardUid;
        $card->user_id = 1;
        $card->user = $user;
        $card->is_active = true;


        $wallet = Mockery::mock(Wallet::class)->makePartial();
        $wallet->id = 1;
        $wallet->balance = $oldBalance;

        $transaction = Mockery::mock(Transaction::class)->makePartial();
        $transaction->id = 1;
        $transaction->user_id = 1;
        $transaction->canteen_id = null;
        $transaction->type = 'top up';
        $transaction->amount = $amount;

        $this->transactionRepository
            ->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->once()
            ->andReturn($card);

        $this->transactionRepository
            ->shouldReceive('findWalletByUserId')
            ->with(1, true)
            ->once()
            ->andReturn($wallet);

        $this->transactionRepository
            ->shouldReceive('updateWallet')
            ->with($wallet, Mockery::type('array'))
            ->once()
            ->andReturn(true);

        $this->transactionRepository->shouldReceive('createTransaction')
            ->with([
                'user_id' => 1,
                'rfid_card_id' => 1,
                'canteen_id' => null,
                'status' => 'berhasil',
                'type' => 'top up',
                'amount' => 50000,
            ])
            ->once()
            ->andReturn($transaction);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();
        DB::shouldReceive('rollback')->never();

        $result = $this->service->handleTopUp($cardUid, $amount);

        expect($result)
            ->toHaveKey('status', 'success')
            ->toHaveKey('message', 'Top up berhasil.');
    });

    it('should return error when card is not found', function () {
        $cardUid = 'BAYU123456';
        $amount = 50000;

        $this->transactionRepository
            ->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->once()
            ->andReturn(null);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();
        DB::shouldReceive('commit')->never();

        $result = $this->service->handleTopUp($cardUid, $amount);

        expect($result)
            ->toHaveKey('status', 'error')
            ->toHaveKey('message', 'Kartu tidak ditemukan atau tidak aktif.');
    });

    it('should return error when card is inactive', function () {
        $cardUid = 'BAYU123456';
        $amount = 50000;

        $this->transactionRepository
            ->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->once()
            ->andReturn(null);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();
        DB::shouldReceive('rollback')->never();


        $result = $this->service->handleTopUp($cardUid, $amount);

        expect($result)
            ->toHaveKey('status', 'error')
            ->toHaveKey('message', 'Kartu tidak ditemukan atau tidak aktif.');
    });

    it('should return error when wallet is not found', function () {
        $cardUid = 'BAYU123456';
        $amount = 50000;

        $user = new User([
            'id' => 1
        ]);

        $card = new RfidCard([
            'id' => 1,
            'card_uid' => $cardUid,
            'user_id' => 1,
            'is_active' => true,
            'user' => $user
        ]);

        $this->transactionRepository
            ->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->once()
            ->andReturn($card);

        $this->transactionRepository
            ->shouldReceive('findWalletByUserId')
            ->with(1, true)
            ->once()
            ->andReturn(null);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollback')->once();
        DB::shouldReceive('commit')->never();

        $result = $this->service->handleTopUp($cardUid, $amount);

        expect($result)
            ->toHaveKey('status', 'error')
            ->toHaveKey('message', 'Wallet pengguna tidak ditemukan.');
    });
});
