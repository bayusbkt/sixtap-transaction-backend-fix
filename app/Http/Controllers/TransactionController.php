<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Helpers\LoginToken;
use App\Http\Requests\TransactionProcessRequest;
use App\Http\Requests\TransactionRefundRequest;
use App\Http\Requests\TransactionRequest;
use App\Http\Requests\TransactionValidateRequest;
use App\Services\TransactionService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{

    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    private function getTransactionFilters(): array
    {
        return [
            request()->query('type'),
            request()->query('range'),
            (int) request()->query('per_page', 50),
        ];
    }

    public function topUp(TransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->transactionService->handleTopUp($validated['card_uid'], $validated['amount']);

        return HandleServiceResponse::format($result);
    }

    public function topUpHistory(): JsonResponse
    {
        [, $range, $perPage] = $this->getTransactionFilters();

        $result = $this->transactionService->getTopUpHistory($range, $perPage);

        return HandleServiceResponse::format($result);
    }

    public function processTransaction(TransactionProcessRequest $request): JsonResponse
    {
        $canteenOpenerId = LoginToken::getUserLoginFromToken($request);
        $validated = $request->validated();

        $result = $this->transactionService->processTransaction(
            $validated['card_uid'],
            $validated['amount'],
            $canteenOpenerId,
            $validated['pin'] ?? null
        );

        return HandleServiceResponse::format($result);
    }

    public function canteenTransactionHistory(): JsonResponse
    {
        [$type, $range, $perpage] = $this->getTransactionFilters();

        $result = $this->transactionService->getCanteenTransactionHistory($type, $range, (int) $perpage);

        return HandleServiceResponse::format($result);
    }

    public function personalTransactionHistory(): JsonResponse
    {
        [$type, $range, $perpage] = $this->getTransactionFilters();
        $userId = LoginToken::getUserLoginFromToken(request());

        $result = $this->transactionService->getPersonalTransactionHistory($type, $range, (int) $perpage, $userId);

        return HandleServiceResponse::format($result);
    }

    public function refundCanteenTransaction(TransactionRefundRequest $request, int $transactionId): JsonResponse
    {
        $validated = $request->validated();
        $canteenOpenerId = LoginToken::getUserLoginFromToken($request);

        $result = $this->transactionService->handleRefundTransaction($transactionId, $canteenOpenerId, $validated['note']);

        return HandleServiceResponse::format($result);
    }

    public function refundTransactionHistory(): JsonResponse
    {
       [, $range, $perPage] = $this->getTransactionFilters();

       $canteenId = request()->query('canteen_id') ? (int) request()->query('canteen_id') : null;

       $result = $this->transactionService->getTransactionRefundHistory($range, $perPage, $canteenId);

       return HandleServiceResponse::format($result);
    }
}
