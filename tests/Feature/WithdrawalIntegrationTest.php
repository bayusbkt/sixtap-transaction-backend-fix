<?php

// tests/Feature/RequestCanteenBalanceExchangeIntegrationTest.php

use App\Services\TransactionService;
use App\Repositories\TransactionRepository;
use App\Models\Canteen;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

describe('Level 1 - Controller Layer', function () {
    it('should return success response when requesting canteen balance exchange', function () {
        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('requestCanteenBalanceExchange')
            ->with(1, 2500000)
            ->andReturn([
                'status' => 'success',
                'message' => 'Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.',
                'data' => [
                    'request_id' => 1,
                    'canteen_id' => 1,
                    'requested_amount' => 2500000,
                    'canteen_balance' => 3000000,
                    'requested_by' => 'John Doe',
                    'status' => 'menunggu',
                    'timestamp' => now()
                ],
                'code' => 200
            ]);

        $this->app->instance(TransactionService::class, $mockService);

        $token = AuthHelper::getCanteenTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/balance-exchange/request/1', [
            'amount' => 2500000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.',
                'data' => [
                    'request_id' => 1,
                    'canteen_id' => 1,
                    'requested_amount' => 2500000,
                    'canteen_balance' => 3000000,
                    'requested_by' => 'John Doe',
                    'status' => 'menunggu'
                ]
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process canteen balance exchange request successfully', function () {
        $mockRepo = Mockery::mock(TransactionRepository::class);

        $mockOpener = Mockery::mock(User::class)->makePartial();
        $mockOpener->id = 107;
        $mockOpener->name = 'John Doe';

        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 3000000;
        $mockCanteen->opened_by = 107;
        $mockCanteen->closed_at = now()->subHour(); // Kantin sudah ditutup
        $mockCanteen->opener = $mockOpener;

        $mockTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockTransaction->id = 1;
        $mockTransaction->created_at = now();

        // Mock repository calls
        $mockRepo->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findPendingWithdrawalForCanteen')
            ->with(1)
            ->andReturn(null); // Tidak ada permintaan pending

        $mockRepo->shouldReceive('createTransaction')
            ->with([
                'user_id' => 107,
                'rfid_card_id' => 0,
                'canteen_id' => 1,
                'type' => 'pencairan',
                'status' => 'menunggu',
                'amount' => 2500000,
                'note' => 'Permintaan pencairan saldo untuk kantin ID: 1'
            ])
            ->andReturn($mockTransaction);

        $service = new TransactionService($mockRepo);

        $result = $service->requestCanteenBalanceExchange(1, 2500000);
        expect($result)->toMatchArray([
            'status' => 'success',
            'message' => 'Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.',
            'data' => [
                'request_id' => 1,
                'canteen_id' => 1,
                'requested_amount' => 2500000,
                'canteen_balance' => 3000000,
                'requested_by' => 'John Doe',
                'status' => 'menunggu',
                'timestamp' => $mockTransaction->created_at
            ],
            'code' => 200
        ]);
    });
});

describe('Level 3 - Full Integration', function () {
    it('should complete canteen balance exchange request flow successfully', function () {
        $mockRepo = Mockery::mock(TransactionRepository::class);

        $mockOpener = Mockery::mock(User::class)->makePartial();
        $mockOpener->id = 107;
        $mockOpener->name = 'John Doe';

        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 3000000;
        $mockCanteen->opened_by = 107;
        $mockCanteen->closed_at = now()->subHour(); // Kantin sudah ditutup
        $mockCanteen->opener = $mockOpener;

        $mockTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockTransaction->id = 1;
        $mockTransaction->created_at = now();

        // Mock repository calls
        $mockRepo->shouldReceive('findCanteenById')
            ->with(1)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findPendingWithdrawalForCanteen')
            ->with(1)
            ->andReturn(null); // Tidak ada permintaan pending

        $mockRepo->shouldReceive('createTransaction')
            ->with([
                'user_id' => 107,
                'rfid_card_id' => 0,
                'canteen_id' => 1,
                'type' => 'pencairan',
                'status' => 'menunggu',
                'amount' => 2500000,
                'note' => 'Permintaan pencairan saldo untuk kantin ID: 1'
            ])
            ->andReturn($mockTransaction);

        $realService = new TransactionService($mockRepo);
        $this->app->instance(TransactionService::class, $realService);

        $token = AuthHelper::getCanteenTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/balance-exchange/request/1', [
            'amount' => 2500000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Permintaan pencairan berhasil. Silahkan tunggu persetujuan dari admin.',
                'data' => [
                    'request_id' => 1,
                    'canteen_id' => 1,
                    'requested_amount' => 2500000,
                    'canteen_balance' => 3000000,
                    'requested_by' => 'John Doe',
                    'status' => 'menunggu'
                ]
            ]);
    });
});
