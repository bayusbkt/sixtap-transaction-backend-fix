<?php

// tests/Feature/AddPinSuccessTest.php

use App\Services\WalletService;
use App\Repositories\WalletRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AuthHelper;

uses(RefreshDatabase::class);


describe('Level 1 - Controller Layer', function () {
    it('should return success response when adding PIN', function () {
        $userId = 107;
        $pin = '123456';

        $mockService = Mockery::mock(WalletService::class);
        $mockService->shouldReceive('addPin')
            ->with($userId, $pin)
            ->andReturn([
                'status' => 'success',
                'message' => 'PIN berhasil ditambahkan.',
                'code' => 200
            ]);

        $this->app->instance(WalletService::class, $mockService);

        $token = AuthHelper::getStudentTokenFromNodejs();
        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/wallet/pin', [
            'pin' => $pin
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'PIN berhasil ditambahkan.',
            ]);
    });
});

describe('Level 2 - Service Layer', function () {
    it('should process PIN addition and return success', function () {
        $userId = 107;
        $pin = '123456';

        $mockRepo = Mockery::mock(WalletRepository::class);

        $mockUser = new User();
        $mockUser->id = $userId;
        $mockUser->pin = null;

        $mockRepo->shouldReceive('findUser')
            ->with($userId)
            ->andReturn($mockUser);

        $mockRepo->shouldReceive('updateUserPin')
            ->with($userId, Mockery::type('string'))
            ->andReturn(true);

        $service = new WalletService($mockRepo);
        $result = $service->addPin($userId, $pin);

        expect($result)->toMatchArray([
            'status' => 'success',
            'message' => 'PIN berhasil ditambahkan.',
            'code' => 200
        ]);
    });
});

describe('Level 3 - Full Integration', function () {
    it('should complete add PIN flow successfully', function () {
        $userId = 107;
        $pin = '123456';

        $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->shouldReceive('getAttribute')
            ->with('id')
            ->andReturn($userId);
        $mockUser->shouldReceive('getAttribute')
            ->with('pin')
            ->andReturn(null);
        $mockUser->shouldReceive('update')
            ->with(['pin' => Mockery::type('string')])
            ->andReturn(true);

        $mockRepo = Mockery::mock(WalletRepository::class);
        $mockRepo->shouldReceive('findUser')
            ->with($userId)
            ->andReturn($mockUser);
        $mockRepo->shouldReceive('updateUserPin')
            ->with($userId, Mockery::type('string'))
            ->andReturn(true);

        $realService = new WalletService($mockRepo);
        $this->app->instance(WalletService::class, $realService);

        $token = AuthHelper::getStudentTokenFromNodejs();
        expect($token)->not->toBeNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/wallet/pin', [
            'pin' => $pin
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'PIN berhasil ditambahkan.',
            ]);
    });
});
