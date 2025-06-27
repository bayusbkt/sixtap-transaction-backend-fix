<?php

use App\Services\WalletService;
use App\Repositories\WalletRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->walletRepository = Mockery::mock(WalletRepository::class);
    $this->service = new WalletService($this->walletRepository);

    Hash::shouldReceive('make')
        ->andReturnUsing(fn($value) => 'hashed-' . $value);

    Hash::shouldReceive('check')
        ->andReturnUsing(fn($plain, $hashed) => $hashed === 'hashed-' . $plain);
});

afterEach(function () {
    Mockery::close();
});

describe('Manage PIN', function () {

    it('should return success when PIN is added', function () {
        $userId = 32;
        $pin = '123456';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => null]);

        $this->walletRepository
            ->shouldReceive('updateUserPin')
            ->with($userId, 'hashed-' . $pin)
            ->andReturnTrue();

        $response = $this->service->addPin($userId, $pin);

        expect($response['status'])->toBe('success');
        expect($response['message'])->toBe('PIN berhasil ditambahkan.');
    });

    it('should return error when user not found', function () {
        $userId = 1035;
        $pin = '123456';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn(null);

        $response = $this->service->addPin($userId, $pin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('Pengguna tidak ditemukan.');
    });

    it('should return error when PIN already set', function () {
        $userId = 32;
        $pin = '123456';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => 'hashed-111111']);

        $response = $this->service->addPin($userId, $pin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('PIN sudah diatur sebelumnya.');
    });

    it('should return success when PIN is changed', function () {
        $userId = 32;
        $oldPin = '123456';
        $newPin = '654321';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => 'hashed-123456']);

        $this->walletRepository
            ->shouldReceive('updateUserPin')
            ->with($userId, 'hashed-' . $newPin)
            ->andReturnTrue();

        $response = $this->service->updatePin($userId, $oldPin, $newPin);

        expect($response['status'])->toBe('success');
        expect($response['message'])->toBe('PIN berhasil diperbarui.');
    });

    it('should return error when user not found on change PIN', function () {
        $userId = 1035;
        $oldPin = '123456';
        $newPin = '654321';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn(null);

        $response = $this->service->updatePin($userId, $oldPin, $newPin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('Pengguna tidak ditemukan.');
    });

    it('should return error when the PIN has not been set before changing it', function () {
        $userId = 32;
        $oldPin = '123456';
        $newPin = '654321';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => null]);

        $response = $this->service->updatePin($userId, $oldPin, $newPin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('PIN lama belum diatur. Silakan atur PIN terlebih dahulu.');
    });

    it('should return error when the old PIN is incorrect', function () {
        $userId = 32;
        $oldPin = 'wrong-pin';
        $newPin = '654321';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => 'hashed-123456']);

        $response = $this->service->updatePin($userId, $oldPin, $newPin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('PIN lama tidak sesuai.');
    });

    it('should return error when the new PIN is same as the old PIN', function () {
        $userId = 32;
        $oldPin = '123456';
        $newPin = '123456';

        $this->walletRepository
            ->shouldReceive('findUser')
            ->with($userId)
            ->andReturn((object)['pin' => 'hashed-123456']);

        $response = $this->service->updatePin($userId, $oldPin, $newPin);

        expect($response['status'])->toBe('error');
        expect($response['message'])->toBe('PIN baru tidak boleh sama dengan PIN lama.');
    });
});
