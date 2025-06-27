<?php

// tests/Feature/CheckBalanceIntegrationTest.php

use App\Services\TransactionService;
use App\Repositories\TransactionRepository;
use App\Models\RfidCard;
use App\Models\Wallet;
use App\Models\User;
use App\Repositories\WalletRepository;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

describe('Level 1 - Controller Layer', function () {
    it('should return success response when checking balance', function () {
        $mockService = Mockery::mock(WalletService::class);
        $mockService->shouldReceive('getBalance')
            ->andReturn([
                'status' => 'success',
                'message' => 'Cek saldo berhasil.',
                'data' => [
                    'name' => 'John Doe',
                    'card_uid' => 'ABC123',
                    'balance' => 100000,
                    'last_top_up' => null
                ],
                'code' => 200
            ]);

        $this->app->instance(WalletService::class, $mockService);

        $token = AuthHelper::getStudentTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/transaction/check-balance');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Cek saldo berhasil.',
                'data' => [
                    'name' => 'John Doe',
                    'card_uid' => 'ABC123',
                    'balance' => 100000,
                    'last_top_up' => null
                ]
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process check balance successfully', function () {
        $mockRepo = Mockery::mock(WalletRepository::class);
        
        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->name = 'John Doe';
        
        $mockRfidCard = Mockery::mock(RfidCard::class)->makePartial();
        $mockRfidCard->card_uid = 'ABC123';
        
        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->user_id = 107;
        $mockWallet->balance = 100000;
        $mockWallet->last_top_up = null;
        $mockWallet->user = $mockUser;
        $mockWallet->rfidCard = $mockRfidCard;

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107)
            ->andReturn($mockWallet);

        $service = new WalletService($mockRepo);

        $result = $service->getBalance(107);

        expect($result)->toMatchArray([
            'status' => 'success',
            'message' => 'Cek saldo berhasil.',
            'data' => [
                'name' => 'John Doe',
                'card_uid' => 'ABC123',
                'balance' => 100000,
                'last_top_up' => null
            ],
            'code' => 200
        ]);
    });
});

describe('Level 3 - Full Integration', function () {
    it('should complete check balance flow successfully', function () {
        $mockRepo = Mockery::mock(WalletRepository::class);
        
        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->name = 'John Doe';
        
        $mockRfidCard = Mockery::mock(RfidCard::class)->makePartial();
        $mockRfidCard->card_uid = 'ABC123EFG';
        
        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->user_id = 107;
        $mockWallet->balance = 100000;
        $mockWallet->last_top_up = null;
        $mockWallet->user = $mockUser;
        $mockWallet->rfidCard = $mockRfidCard;

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(107)
            ->andReturn($mockWallet);

        $realService = new WalletService($mockRepo);
        $this->app->instance(WalletService::class, $realService);

        $token = AuthHelper::getStudentTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/transaction/check-balance');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Cek saldo berhasil.',
                'data' => [
                    'name' => 'John Doe',
                    'card_uid' => 'ABC123EFG',
                    'balance' => 100000,
                    'last_top_up' => null
                ]
            ]);
    });
});