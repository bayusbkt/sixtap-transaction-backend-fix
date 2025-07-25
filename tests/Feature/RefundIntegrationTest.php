<?php
// tests/Feature/RefundTransactionSuccessTest.php

use App\Services\TransactionService;
use App\Repositories\TransactionRepository;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Canteen;
use App\Models\RfidCard;
use App\Helpers\LoginToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);

describe('Level 1 - Controller Layer', function () {
    it('should return success response when refunding transaction', function () {
        $transactionId = 123;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $mockService = Mockery::mock(TransactionService::class);
        $mockService->shouldReceive('handleRefundTransaction')
            ->with($transactionId, $canteenOpenerId, $note)
            ->andReturn([
                'status' => 'success',
                'message' => 'Refund transaksi berhasil dilakukan.',
                'data' => [
                    [
                        'refund_transaction_id' => 456,
                        'original_transaction_id' => $transactionId,
                        'user' => ['id' => 1, 'name' => 'John Doe'],
                        'refund_amount' => 10000,
                        'canteen_id' => 1,
                        'timestamp' => now(),
                        'note' => $note
                    ]
                ],
                'code' => 200
            ]);

        $this->app->instance(TransactionService::class, $mockService);

        $mockLoginToken = Mockery::mock('alias:' . LoginToken::class);
        $mockLoginToken->shouldReceive('getUserLoginFromToken')
            ->andReturn($canteenOpenerId);

        $token = AuthHelper::getCanteenTokenFromNodejs();
        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson("/api/transaction/{$transactionId}/refund", [
            'note' => $note
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Refund transaksi berhasil dilakukan.',
                'data' => [
                    [
                        'refund_transaction_id' => 456,
                        'original_transaction_id' => $transactionId,
                        'refund_amount' => 10000,
                        'note' => $note
                    ]
                ]
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process refund transaction and return success', function () {
        $transactionId = 123;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        $mockRepo = Mockery::mock(TransactionRepository::class);

        $mockOriginalTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockOriginalTransaction->id = $transactionId;
        $mockOriginalTransaction->user_id = 1;
        $mockOriginalTransaction->rfid_card_id = 1;
        $mockOriginalTransaction->canteen_id = 1;
        $mockOriginalTransaction->amount = 10000;

        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->id = 1;
        $mockUser->name = 'John Doe';
        $mockUser->shouldReceive('only')
            ->with(['id', 'name'])
            ->andReturn(['id' => 1, 'name' => 'John Doe']);
        $mockOriginalTransaction->user = $mockUser;

        $mockCanteen = Mockery::mock(Canteen::class)->makePartial();
        $mockCanteen->id = 1;
        $mockCanteen->current_balance = 50000;

        $mockWallet = Mockery::mock(Wallet::class)->makePartial();
        $mockWallet->balance = 20000;

        $mockRefundTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockRefundTransaction->id = 456;
        $mockRefundTransaction->created_at = now();

        $mockRepo->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->andReturn($mockOriginalTransaction);

        $mockRepo->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->andReturn(null);

        $mockRepo->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($mockCanteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with(1, true)
            ->andReturn($mockWallet);

        $mockRepo->shouldReceive('updateWallet')
            ->with($mockWallet, ['balance' => 30000])
            ->andReturn(true);

        $mockRepo->shouldReceive('updateCanteen')
            ->with($mockCanteen, ['current_balance' => 40000])
            ->andReturn(true);

        $mockRepo->shouldReceive('createTransaction')
            ->with(Mockery::type('array'))
            ->andReturn($mockRefundTransaction);

        $mockEmail = Mockery::mock('alias:App\Helpers\HandleEmailNotification');
        $mockEmail->shouldReceive('refund')->andReturn(null);

        $mockLogger = Mockery::mock('alias:App\Helpers\LogFailedTransaction');
        $mockLogger->shouldReceive('format')->andReturn(null);

        $service = new TransactionService($mockRepo);
        $result = $service->handleRefundTransaction($transactionId, $canteenOpenerId, $note);
        expect($result)->toMatchArray([
            'status' => 'success',
            'message' => 'Refund transaksi berhasil dilakukan.',
            'code' => 200
        ]);

        expect($result['data'][0])->toHaveKeys([
            'refund_transaction_id',
            'original_transaction_id',
            'user',
            'refund_amount',
            'canteen_id',
            'timestamp',
            'note'
        ]);
    });
});

describe('Level 3 - Full Integration', function () {
    it('should complete refund transaction flow successfully', function () {
        $transactionId = 123;
        $canteenOpenerId = 1;
        $note = "Salah input harga";

        // Mock User
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->name = 'John Doe';
        $user->shouldReceive('only')->with(['id', 'name'])->andReturn(['id' => 1, 'name' => 'John Doe']);

        // Mock Card
        $card = Mockery::mock(RfidCard::class)->makePartial();
        $card->id = 1;
        $card->card_uid = 'ABC123EFG';
        $card->user_id = $user->id;

        // Mock Canteen
        $canteen = Mockery::mock(Canteen::class)->makePartial();
        $canteen->id = 1;
        $canteen->current_balance = 50000;

        // Mock Wallet
        $wallet = Mockery::mock(Wallet::class)->makePartial();
        $wallet->user_id = $user->id;
        $wallet->balance = 20000;

        // Mock Original Transaction
        $originalTransaction = Mockery::mock(Transaction::class)->makePartial();
        $originalTransaction->id = $transactionId;
        $originalTransaction->user_id = $user->id;
        $originalTransaction->rfid_card_id = $card->id;
        $originalTransaction->canteen_id = $canteen->id;
        $originalTransaction->amount = 10000;
        $originalTransaction->user = $user;
        $originalTransaction->canteen = $canteen;
        $originalTransaction->rfidCard = $card;

        $mockRepo = Mockery::mock(TransactionRepository::class);

        $mockRepo->shouldReceive('findSuccessfulPurchaseTransaction')
            ->with($transactionId)
            ->andReturn($originalTransaction);

        $mockRepo->shouldReceive('findExistingRefund')
            ->with($transactionId)
            ->andReturn(null);

        $mockRepo->shouldReceive('findOpenCanteenByOpener')
            ->with($canteenOpenerId)
            ->andReturn($canteen);

        $mockRepo->shouldReceive('findWalletByUserId')
            ->with($user->id, true)
            ->andReturn($wallet);

        $mockRepo->shouldReceive('updateWallet')
            ->with($wallet, ['balance' => 30000])
            ->andReturnUsing(function ($wallet, $data) {
                $wallet->update($data);
                return true;
            });

        $mockRepo->shouldReceive('updateCanteen')
            ->with($canteen, ['current_balance' => 40000])
            ->andReturnUsing(function ($canteen, $data) {
                $canteen->update($data);
                return true;
            });

        $mockRefundTransaction = Mockery::mock(Transaction::class)->makePartial();
        $mockRefundTransaction->id = 456;
        $mockRefundTransaction->created_at = now();

        $mockRepo->shouldReceive('createTransaction')
            ->with(Mockery::type('array'))
            ->andReturn($mockRefundTransaction);

        $mockEmail = Mockery::mock('alias:App\Helpers\HandleEmailNotification');
        $mockEmail->shouldReceive('refund')->andReturn(null);

        $mockLogger = Mockery::mock('alias:App\Helpers\LogFailedTransaction');
        $mockLogger->shouldReceive('format')->andReturn(null);

        $realService = new TransactionService($mockRepo);
        $this->app->instance(TransactionService::class, $realService);

        $mockLoginToken = Mockery::mock('alias:' . LoginToken::class);
        $mockLoginToken->shouldReceive('getUserLoginFromToken')
            ->andReturn($canteenOpenerId);

        $token = AuthHelper::getCanteenTokenFromNodejs();
        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson("/api/transaction/{$transactionId}/refund", [
            'note' => $note
        ]);
        
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Refund transaksi berhasil dilakukan.',
            ]);
    });
});
