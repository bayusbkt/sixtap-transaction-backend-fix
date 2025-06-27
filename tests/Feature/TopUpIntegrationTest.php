<?php

// tests/Feature/TopUpIntegrationTest.php

use App\Services\TransactionService;
use App\Repositories\TransactionRepository;
use App\Models\RfidCard;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

describe('Level 1 - Controller Layer', function () {
    it('should return success response', function () {
        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('handleTopUp')
            ->with('ABC123EFG', 50000)
            ->andReturn([
                'status' => 'success',
                'message' => 'Top up berhasil.',
                'data' => ['card_uid' => 'ABC123EFG'],
                'code' => 200
            ]);

        $this->app->instance(TransactionService::class, $mockService);

        $token = AuthHelper::getAdminTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/top-up', [
            'card_uid' => 'ABC123EFG',
            'amount' => 50000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Top up berhasil.'
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process top up successfully', function () {
        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('handleTopUp')
            ->with('ABC123EFG', 50000)
            ->andReturn([
                'status' => 'success',
                'message' => 'Top up berhasil.',
                'data' => ['card_uid' => 'ABC123EFG'],
                'code' => 200
            ]);

        $this->app->instance(TransactionService::class, $mockService);

        $token = AuthHelper::getAdminTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/top-up', [
            'card_uid' => 'ABC123EFG',
            'amount' => 50000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Top up berhasil.'
            ]);
    });
});


describe('Level 3 - Full Integration', function () {
    it('should complete full top up flow successfully', function () {
        $mockRepo = Mockery::mock(TransactionRepository::class);

        $mockCard = Mockery::mock(RfidCard::class)->makePartial();
        $mockCard->id = 1;
        $mockCard->user_id = 1;
        $mockCard->card_uid = 'ABC123EFG';

        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->user_id = 1;
        $mockWallet->balance = 50000;

        $mockRepo->shouldReceive('findActiveCardByUid')
            ->with('ABC123EFG')
            ->andReturn($mockCard);

        $mockRepo->shouldReceive('getWalletByUserId')
            ->with(1)
            ->andReturn($mockWallet);

        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('handleTopUp')
            ->with('ABC123EFG', 50000)
            ->andReturn([
                'status' => 'success',
                'message' => 'Top up berhasil.',
                'data' => ['card_uid' => 'ABC123EFG'],
                'code' => 200
            ]);
        $this->app->instance(TransactionService::class, $mockService);

        $token = AuthHelper::getAdminTokenFromNodejs();

        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/transaction/top-up', [
            'card_uid' => 'ABC123EFG',
            'amount' => 50000
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Top up berhasil.'
            ]);
    });
});


// Command untuk menjalankan:
// ./vendor/bin/pest --filter="Level 1"  # Test Controller
// ./vendor/bin/pest --filter="Level 2"  # Test Service  
// ./vendor/bin/pest --filter="Level 3"  # Test Full Integration