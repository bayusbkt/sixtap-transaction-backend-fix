<?php

// tests/Feature/PurchaseTransactionIntegrationTest.php

use App\Helpers\HandleEmailNotification;
use App\Services\TransactionService;
use App\Repositories\TransactionRepository;
use App\Models\Canteen;
use App\Models\Transaction;
use App\Models\User;
use App\Models\RfidCard;
use App\Models\Wallet;
use App\Models\Absence;
use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

describe('Level 1 - Controller Layer', function () {
    it('should return success response when processing purchase transaction', function () {
        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('handlePurchase')
            ->with('ABC123EFG', 12000, 106, Mockery::any())
            ->andReturn([
                'status' => 'success',
                'message' => 'Transaksi pembelian berhasil dilakukan.',
                'data' => [[
                    'transaction_id' => 1,
                    'user' => [
                        'id' => 107,
                        'name' => 'John Doe'
                    ],
                    'amount' => 12000,
                    'canteen_id' => 1,
                    'timestamp' => now()
                ]],
                'code' => 200
            ]);

        $this->app->instance(TransactionService::class, $mockService);

        $token = AuthHelper::getCanteenTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/purchase', [
            'card_uid' => 'ABC123EFG',
            'amount' => 12000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Transaksi pembelian berhasil dilakukan.',
                'data' => [[
                    'transaction_id' => 1,
                    'user' => [
                        'id' => 107,
                        'name' => 'John Doe'
                    ],
                    'amount' => 12000,
                    'canteen_id' => 1
                ]]
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process purchase transaction successfully', function () {
        $mockRepo = Mockery::mock(TransactionRepository::class);

        // Mock User
        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->id = 107;
        $mockUser->name = 'John Doe';
        $mockUser->pin = bcrypt('123456');

        // Mock School Class
        $mockSchoolClass = Mockery::mock(SchoolClass::class)->makePartial();
        $mockSchoolClass->id = 1;
        $mockSchoolClass->class_name = '12 IPA 1';

        // Mock RFID Card
        $mockCard = Mockery::mock(RfidCard::class)->makePartial();
        $mockCard->id = 1;
        $mockCard->card_uid = 'ABC123EFG';
        $mockCard->user_id = 107;
        $mockCard->is_active = true;
        $mockCard->user = $mockUser;

        // Mock Wallet
        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->user_id = 107;
        $mockWallet->balance = 50000;

        // Mock Absence
        $mockAbsence = Mockery::mock(Absence::class)->makePartial();
        $mockAbsence->user_id = 107;
        $mockAbsence->day = 'Friday';
        $mockAbsence->time_in = now()->subHours(2);
        $mockAbsence->time_out = null;

        // Mock Canteen Opener
        $mockOpener = Mockery::mock(User::class)->makePartial();
        $mockOpener->id = 106;
        $mockOpener->name = 'Canteen Owner';

        // Mock Canteen
        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 100000;
        $mockCanteen->opened_by = 106;
        $mockCanteen->opened_at = now()->subHour();
        $mockCanteen->closed_at = null;
        $mockCanteen->opener = $mockOpener;

        // Mock Transaction
        $mockTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockTransaction->id = 1;
        $mockTransaction->created_at = now();

        // Mock repository calls for validation
        $mockRepo->shouldReceive('findRfidCardByUid')
            ->with('ABC123EFG')
            ->andReturn($mockCard);

        $mockRepo->shouldReceive('findAbsenceForToday')
            ->with(107)
            ->andReturn($mockAbsence);

        $mockRepo->shouldReceive('findOpenCanteenByOpener')
            ->with(106)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107)
            ->andReturn($mockWallet);

        // Mock repository calls for transaction processing
        $mockRepo->shouldReceive('findRfidCardByUidWithRelation')
            ->with('ABC123EFG')
            ->andReturn($mockCard);

        $mockRepo->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107, true)
            ->andReturn($mockWallet);

        $mockRepo->shouldReceive('updateWallet')
            ->with($mockWallet, ['balance' => 38000])
            ->andReturn(true);

        $mockRepo->shouldReceive('updateCanteen')
            ->with($mockCanteen, ['current_balance' => 112000])
            ->andReturn(true);

        $mockRepo->shouldReceive('createTransaction')
            ->with([
                'user_id' => 107,
                'rfid_card_id' => 1,
                'canteen_id' => 1,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => 12000,
            ])
            ->andReturn($mockTransaction);

        $mockEmail = Mockery::mock('alias:App\Helpers\HandleEmailNotification');
        $mockEmail->shouldReceive('purchase')->andReturn(null);

        $mockLogger = Mockery::mock('alias:App\Helpers\LogFailedTransaction');
        $mockLogger->shouldReceive('format')->andReturn(null);

        $service = new TransactionService($mockRepo);

        $result = $service->handlePurchase('ABC123EFG', 12000, 106);

        expect($result)->toMatchArray([
            'status' => 'success',
            'message' => 'Transaksi pembelian berhasil dilakukan.',
            'data' => [[
                'transaction_id' => 1,
                'user' => [
                    'id' => 107,
                    'name' => 'John Doe'
                ],
                'amount' => 12000,
                'canteen_id' => 1,
                'timestamp' => $mockTransaction->created_at
            ]],
            'code' => 200
        ]);
    });
});

describe('Level 3 - Full Integration', function () {
    it('should complete purchase transaction flow successfully', function () {
        $mockRepo = Mockery::mock(TransactionRepository::class);

        // Mock User
        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->id = 107;
        $mockUser->name = 'John Doe';
        $mockUser->pin = bcrypt('123456');

        // Mock RFID Card
        $mockCard = Mockery::mock(RfidCard::class)->makePartial();
        $mockCard->id = 1;
        $mockCard->card_uid = 'ABC123EFG';
        $mockCard->user_id = 107;
        $mockCard->is_active = true;
        $mockCard->user = $mockUser;

        // Mock Wallet
        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->user_id = 107;
        $mockWallet->balance = 50000;

        // Mock Absence
        $mockAbsence = Mockery::mock(Absence::class)->makePartial();
        $mockAbsence->user_id = 107;
        $mockAbsence->day = 'Friday';
        $mockAbsence->time_in = now()->subHours(2);
        $mockAbsence->time_out = null;

        // Mock Canteen Opener
        $mockOpener = Mockery::mock(User::class)->makePartial();
        $mockOpener->id = 106;
        $mockOpener->name = 'Canteen Owner';

        // Mock Canteen
        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 100000;
        $mockCanteen->opened_by = 106;
        $mockCanteen->opened_at = now()->subHour();
        $mockCanteen->closed_at = null;
        $mockCanteen->opener = $mockOpener;

        // Mock Transaction
        $mockTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockTransaction->id = 1;
        $mockTransaction->created_at = now();

        // Setup all repository method calls
        $mockRepo->shouldReceive('findRfidCardByUid')
            ->with('ABC123EFG')
            ->andReturn($mockCard);

        $mockRepo->shouldReceive('findAbsenceForToday')
            ->with(107)
            ->andReturn($mockAbsence);

        $mockRepo->shouldReceive('findOpenCanteenByOpener')
            ->with(106)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107)
            ->andReturn($mockWallet);

        $mockRepo->shouldReceive('findRfidCardByUidWithRelation')
            ->with('ABC123EFG')
            ->andReturn($mockCard);

        $mockRepo->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107, true)
            ->andReturn($mockWallet);

        $mockRepo->shouldReceive('updateWallet')
            ->with($mockWallet, ['balance' => 38000])
            ->andReturn(true);

        $mockRepo->shouldReceive('updateCanteen')
            ->with($mockCanteen, ['current_balance' => 112000])
            ->andReturn(true);

        $mockRepo->shouldReceive('createTransaction')
            ->with([
                'user_id' => 107,
                'rfid_card_id' => 1,
                'canteen_id' => 1,
                'type' => 'pembelian',
                'status' => 'berhasil',
                'amount' => 12000,
            ])
            ->andReturn($mockTransaction);

             $mockEmail = Mockery::mock('alias:App\Helpers\HandleEmailNotification');
        $mockEmail->shouldReceive('purchase')->andReturn(null);

        $mockLogger = Mockery::mock('alias:App\Helpers\LogFailedTransaction');
        $mockLogger->shouldReceive('format')->andReturn(null);

        $realService = new TransactionService($mockRepo);
        $this->app->instance(TransactionService::class, $realService);

        $token = AuthHelper::getCanteenTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/purchase', [
            'card_uid' => 'ABC123EFG',
            'amount' => 12000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Transaksi pembelian berhasil dilakukan.',
                'data' => [[
                    'transaction_id' => 1,
                    'user' => [
                        'id' => 107,
                        'name' => 'John Doe'
                    ],
                    'amount' => 12000,
                    'canteen_id' => 1
                ]]
            ]);
    });
});
