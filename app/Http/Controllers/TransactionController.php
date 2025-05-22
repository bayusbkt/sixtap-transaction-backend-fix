<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Http\Requests\TransactionRequest;
use App\Services\TransactionService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService, WalletService $walletService)
    {
        $this->transactionService = $transactionService;
    }

    public function topUp(TransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->transactionService->handleTopUp($validated['card_uid'], $validated['amount']);

        return HandleServiceResponse::format($result);
    }

    public function topUpHistory(): JsonResponse
    {
        $result = $this->transactionService->getTopUpHistory();

        return HandleServiceResponse::format($result);
    }

    public function cardTransaction(TransactionRequest $request): JsonResponse
    {
        $canteenId = $request->input('auth_user')['id'];

        $validated = $request->validated();
        $result = $this->transactionService->startTransaction($validated['card_uid'], $validated['amount'], $canteenId);

        return HandleServiceResponse::format($result);
    }
}
