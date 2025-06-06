<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Helpers\LoginToken;
use App\Http\Requests\PinRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function checkBalance(): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken(request());

        $result = $this->walletService->getBalance($userId);

        return HandleServiceResponse::format($result);
    }

    public function addPin(PinRequest $request): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken(request());

        $validated = $request->validated();
        $result = $this->walletService->addPin($userId, $validated['pin']);

        return HandleServiceResponse::format($result);
    }

    public function updatePin(PinRequest $request): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken(request());

        $validated = $request->validated();
        $result = $this->walletService->updatePin($userId, $validated['old_pin'], $validated['pin']);

        return HandleServiceResponse::format($result);
    }
}
