<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Http\Requests\PinRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = new WalletService();
    }

    public function checkBalance(int $userId): JsonResponse
    {
        $result = $this->walletService->getBalance($userId);

        return HandleServiceResponse::format($result);
    }

    public function addPin(PinRequest $request, int $userId): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->walletService->addPin($userId, $validated['pin']);

        return HandleServiceResponse::format($result);
    }

    public function updatePin(PinRequest $request, int $userId): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->walletService->updatePin($userId, $validated['pin']);

        return HandleServiceResponse::format($result);
    }
}
