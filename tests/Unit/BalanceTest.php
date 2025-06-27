<?php

use App\Models\RfidCard;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\WalletRepository;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->walletRepository = Mockery::mock(WalletRepository::class);
    $this->service = new WalletService($this->walletRepository);
});

afterEach(function () {
    Mockery::close();
});

describe('Check Balance', function () {
    it('should return success when wallet is found ', function () {
        $userId = 32;

        $wallet = (object)[
            'balance' => 75000,
            'last_top_up' => '2025-06-20 15:30:00',
            'user' => (object)[
                'name' => 'Bayu Subekti'
            ],
            'rfidCard' => (object)[
                'card_uid' => 'ABC123EFG'
            ]
        ];

        $this->walletRepository
            ->shouldReceive('findWalletByUserId')
            ->with($userId)
            ->once()
            ->andReturn($wallet);

        $result = $this->service->getBalance($userId);

        expect($result)
            ->toHaveKey('status', 'success')
            ->toHaveKey('message', 'Cek saldo berhasil.')
            ->and($result['data'])->toMatchArray([
                'name' => 'Bayu Subekti',
                'card_uid' => 'ABC123EFG',
                'balance' => 75000,
                'last_top_up' => '2025-06-20 15:30:00',
            ]);
    });

    it('should return error when wallet is not found', function () {
        $userId = 1025;

        $this->walletRepository
            ->shouldReceive('findWalletByUserId')
            ->with($userId)
            ->once()
            ->andReturn(null);

        $result = $this->service->getBalance($userId);

        expect($result)
            ->toHaveKey('status', 'error')
            ->toHaveKey('message', 'Wallet pengguna tidak ditemukan.')
            ->toHaveKey('code', 404);
    });
});
