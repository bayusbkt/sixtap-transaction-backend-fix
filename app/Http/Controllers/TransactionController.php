<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Helpers\LoginToken;
use App\Http\Requests\CanteenBalanceExchangeRequest;
use App\Http\Requests\CanteenBalanceRejectRequest;
use App\Http\Requests\DateRequest;
use App\Http\Requests\TransactionProcessRequest;
use App\Http\Requests\TransactionRefundRequest;
use App\Http\Requests\TransactionRequest;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{

    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    private function transactionFilters(): array
    {
        return [
            'type'     => request()->query('type'),
            'status'   => request()->query('status'),
            'per_page' => (int) request()->query('per_page', 50),
        ];
    }

    public function topUp(TransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->transactionService->handleTopUp($validated['card_uid'], $validated['amount']);

        return HandleServiceResponse::format($result);
    }

    public function topUpHistory(DateRequest $request): JsonResponse
    {
        $filters = $this->transactionFilters();
        $perPage = $filters['per_page'];

        $validated = $request->validated();

        $result = $this->transactionService->getTopUpHistory(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage
        );

        return HandleServiceResponse::format($result);
    }

    public function processPurchase(TransactionProcessRequest $request): JsonResponse
    {
        $canteenOpenerId = LoginToken::getUserLoginFromToken($request);
        $validated = $request->validated();

        $result = $this->transactionService->handlePurchase(
            $validated['card_uid'],
            $validated['amount'],
            $canteenOpenerId,
            $validated['pin'] ?? null
        );

        return HandleServiceResponse::format($result);
    }

    public function purchaseDetailById(int $transactionId): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken(request());
        $result = $this->transactionService->getPurchaseDetail($userId, $transactionId);

        return HandleServiceResponse::format($result);
    }

    public function canteenTransactionHistory(DateRequest $request): JsonResponse
    {
        $filters = $this->transactionFilters();
        $type = $filters['type'];
        $status = $filters['status'];
        $perPage = $filters['per_page'];

        $validated = $request->validated();

        $result = $this->transactionService->getCanteenTransactionHistory(
            $type,
            $status,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage
        );

        return HandleServiceResponse::format($result);
    }

    public function personalTransactionHistory(DateRequest $request): JsonResponse
    {
        $filters = $this->transactionFilters();
        $type = $filters['type'];
        $status = $filters['status'];
        $perPage = $filters['per_page'];

        $userId = LoginToken::getUserLoginFromToken(request());

        $validated = $request->validated();

        $result = $this->transactionService->getPersonalTransactionHistory(
            $type,
            $status,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage,
            $userId
        );

        return HandleServiceResponse::format($result);
    }

    public function refundCanteenTransaction(TransactionRefundRequest $request, int $transactionId): JsonResponse
    {
        $validated = $request->validated();
        $canteenOpenerId = LoginToken::getUserLoginFromToken($request);

        $result = $this->transactionService->handleRefundTransaction($transactionId, $canteenOpenerId, $validated['note']);

        return HandleServiceResponse::format($result);
    }

    public function refundDetailById(int $refundTransactionId): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken(request());

        $result = $this->transactionService->getRefundDetail($userId, $refundTransactionId);

        return HandleServiceResponse::format($result);
    }

    public function requestCanteenBalanceExchange(CanteenBalanceExchangeRequest $request, int $canteenId): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->transactionService->requestCanteenBalanceExchange(
            $canteenId,
            $validated['amount']
        );

        return HandleServiceResponse::format($result);
    }

    public function acceptCanteenBalanceExchange(Request $request, int $requestId): JsonResponse
    {
        $adminId = LoginToken::getUserLoginFromToken($request);

        $result = $this->transactionService->approveCanteenBalanceExchange($requestId, $adminId);

        return HandleServiceResponse::format($result);
    }

    public function rejectCanteenBalanceExchange(CanteenBalanceRejectRequest $request, int $requestId): JsonResponse
    {
        $validated = $request->validated();
        $adminId = LoginToken::getUserLoginFromToken($request);

        $result = $this->transactionService->rejectCanteenBalanceExchange(
            $requestId,
            $adminId,
            $validated['rejection_reason']
        );

        return HandleServiceResponse::format($result);
    }

    public function withdrawalDetailById(int $withdrawalId): JsonResponse
    {
        $result = $this->transactionService->getWithdrawalDetail($withdrawalId);

        return HandleServiceResponse::format($result);
    }

    public function getPendingWithdrawalRequests(): JsonResponse
    {
        $perPage = (int) request()->query('per_page', 50);

        $result = $this->transactionService->getPendingWithdrawalRequests($perPage);

        return HandleServiceResponse::format($result);
    }
}
