<?php

use App\Repositories\TransactionRepository;
use App\Services\TransactionService;
use App\Models\Canteen;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->transactionRepository = Mockery::mock(TransactionRepository::class);
    $this->service = new TransactionService($this->transactionRepository);
    DB::shouldReceive('beginTransaction')->andReturnTrue();
    DB::shouldReceive('commit')->andReturnTrue();
    DB::shouldReceive('rollBack')->andReturnTrue();

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

describe('Handle Transaction Purchase', function () {
    it('should return success when purchase transaction is valid', function () {
        $cardUid = "ABC12EFG";
        $amount = 12000;
        $canteenOpenerId = 1;

        $userMock = Mockery::mock(User::class)->makePartial();
        $userMock->id = 1;
        $userMock->name = 'John Doe';
        $userMock->shouldReceive('only')->with(['id', 'name'])->andReturn([
            'id' => 1,
            'name' => 'John Doe',
        ]);

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true,
            'user' => $userMock
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => null
        ];

        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 100000;
        $mockCanteen->opened_at = \Carbon\Carbon::now();
        $mockCanteen->opener = (object) ['name' => 'Penjaga Kantin'];


        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->id = 1;
        $mockWallet->balance = 50000;

        $mockTransaction = (object) [
            'id' => 1,
            'created_at' => \Carbon\Carbon::now()
        ];

        $newBalance = $mockWallet->balance - $amount;

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $this->transactionRepository->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1)
            ->andReturn($mockWallet);

        $this->transactionRepository->shouldReceive('findRfidCardByUidWithRelation')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1, true)
            ->andReturn($mockWallet);

        $this->transactionRepository->shouldReceive('updateWallet')
            ->with($mockWallet, ['balance' => $newBalance])
            ->once()
            ->andReturn(true);

        $this->transactionRepository->shouldReceive('updateCanteen')
            ->with($mockCanteen, ['current_balance' => 112000])
            ->once()
            ->andReturn(true);

        $this->transactionRepository->shouldReceive('createTransaction')
            ->with([
                'user_id' => 1,
                'rfid_card_id' => 1,
                'canteen_id' => 1,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => $amount,
            ])
            ->andReturn($mockTransaction);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('success');
        expect($result['message'])->toBe('Transaksi pembelian berhasil dilakukan.');
    });

    it('should return error when card is not found', function () {
        $cardUid = "BAYU123456";
        $amount = 12000;
        $canteenOpenerId = 1;

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn(null);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Kartu tidak ditemukan atau tidak terhubung dengan pengguna.');
    });

    it('should return error when card is inactive', function () {
        $cardUid = "EFG123ABC";
        $amount = 12000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => false
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Kartu tidak aktif.');
    });

    it('should return error when student has not checked in today', function () {
        $cardUid = "ABC123EFG";
        $amount = 12000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn(null);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Siswa belum melakukan absensi hari ini.');
    });

    it('should return error when student has already checked out', function () {
        $cardUid = "ABC123EFG";
        $amount = 12000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => '17:00:00'
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Siswa sudah melakukan absensi keluar. Transaksi tidak dapat dilakukan.');
    });

    it('should return error when wallet is not found', function () {
        $cardUid = "ABC123EFG";
        $amount = 12000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => null
        ];

        $mockCanteen = (object) [
            'id' => 1,
            'opened_at' => now()
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $this->transactionRepository->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1)
            ->andReturn(null);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Wallet pengguna tidak ditemukan.');
    });

    it('should return error when balance is insufficient', function () {
        $cardUid = "ABC123EFG";
        $amount = 12000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => null
        ];

        $mockCanteen = (object) [
            'id' => 1,
            'opened_at' => now()
        ];

        $mockWallet = (object) [
            'user_id' => 1,
            'balance' => 5000
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $this->transactionRepository->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1)
            ->andReturn($mockWallet);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('Saldo tidak mencukupi.');
    });

    it('should return success when processing large transaction with a valid PIN', function () {
        $cardUid = "ABC123EFG";
        $amount = 25000;
        $canteenOpenerId = 1;
        $pin = "123456";

        $userMock = Mockery::mock(User::class)->makePartial();
        $userMock->id = 1;
        $userMock->name = 'John Doe';
        $userMock->pin = Hash::make($pin);
        $userMock->shouldReceive('only')->with(['id', 'name'])->andReturn([
            'id' => 1,
            'name' => 'John Doe',
        ]);

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true,
            'user' => $userMock
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => null
        ];


        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 100000;
        $mockCanteen->opened_at = \Carbon\Carbon::now();
        $mockCanteen->opener = (object) ['name' => 'Penjaga Kantin'];


        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->id = 1;
        $mockWallet->balance = 50000;

        $mockTransaction = (object) [
            'id' => 1,
            'created_at' => \Carbon\Carbon::now()
        ];

        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $this->transactionRepository->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1)
            ->andReturn($mockWallet);

        $this->transactionRepository->shouldReceive('findRfidCardByUidWithRelation')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1, true)
            ->andReturn($mockWallet);

        $this->transactionRepository
            ->shouldReceive('updateWallet')
            ->with($mockWallet, Mockery::type('array'))
            ->once()
            ->andReturn(true);

        $this->transactionRepository->shouldReceive('updateCanteen')->once();

        $this->transactionRepository->shouldReceive('createTransaction')
            ->with([
                'user_id' => 1,
                'rfid_card_id' => 1,
                'canteen_id' => 1,
                'status' => 'berhasil',
                'type' => 'pembelian',
                'amount' => 25000,
            ])
            ->once()
            ->andReturn($mockTransaction);;

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId, $pin);

        expect($result['status'])->toBe('success');
        expect($result['message'])->toBe('Transaksi pembelian berhasil dilakukan.');
    });

    it('should return error when processing large transaction without PIN', function () {
        $cardUid = "ABC123EFG";
        $amount = 25000;
        $canteenOpenerId = 1;

        $mockCard = (object) [
            'id' => 1,
            'user_id' => 1,
            'is_active' => true,
            'user' => (object) [
                'id' => 1,
                'name' => 'John Doe',
                'pin' => '123456'
            ]
        ];

        $mockAbsence = (object) [
            'user_id' => 1,
            'time_in' => '08:00:00',
            'time_out' => null
        ];

        $mockCanteen = (object) [
            'id' => 1,
            'opened_at' => \Carbon\Carbon::now(),
            'current_balance' => 50000,
            'opener' => (object) ['name' => 'Canteen Operator']
        ];

        $mockWallet = (object) [
            'user_id' => 1,
            'balance' => 50000
        ];

        // Validation expectations
        $this->transactionRepository->shouldReceive('findRfidCardByUid')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findAbsenceForToday')
            ->with(1)
            ->andReturn($mockAbsence);

        $this->transactionRepository->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $this->transactionRepository->shouldReceive('findWalletByUserId')
            ->with(1)
            ->andReturn($mockWallet);

        $this->transactionRepository->shouldReceive('findRfidCardByUidWithRelation')
            ->with($cardUid)
            ->andReturn($mockCard);

        $this->transactionRepository->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $result = $this->service->handlePurchase($cardUid, $amount, $canteenOpenerId, null);

        expect($result['status'])->toBe('error');
        expect($result['message'])->toBe('PIN diperlukan untuk transaksi di atas Rp 20.000.');
    });
});
