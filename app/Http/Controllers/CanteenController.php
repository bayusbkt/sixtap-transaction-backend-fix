<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Helpers\LoginToken;
use App\Http\Requests\AmountRequest;
use App\Http\Requests\HistoryRequest;
use App\Services\CanteenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CanteenController extends Controller
{
    protected $canteenService;

    public function __construct(CanteenService $canteenService)
    {
        $this->canteenService = $canteenService;
    }

    private function canteenFilters(): int
    {
        return (int) request()->query('per_page', 50);
    }

    public function openCanteen(Request $request): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken($request);

        $result = $this->canteenService->requestOpenCanteen($userId);

        return HandleServiceResponse::format($result);
    }

    public function settleCanteen(Request $request): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken($request);
        $note = $request->input('note');

        $result = $this->canteenService->settleCanteen($userId, $note);

        return HandleServiceResponse::format($result);
    }

    public function closeCanteen(Request $request): JsonResponse
    {
        $userId = LoginToken::getUserLoginFromToken($request);

        $result = $this->canteenService->closeCanteen($userId);

        return HandleServiceResponse::format($result);
    }

    public function initialFund(AmountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = LoginToken::getUserLoginFromToken($request);

        $result = $this->canteenService->inputInitialFund($userId, $validated['amount']);

        return HandleServiceResponse::format($result);
    }

    public function canteenInitialFundHistory(HistoryRequest $request): JsonResponse
    {
        $perPage = $this->canteenFilters();

        $validated = $request->validated();

        $result = $this->canteenService->getCanteenInitialFundHistory(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage
        );

        return HandleServiceResponse::format($result);
    }

    public function generalCanteenIncomeHistory(HistoryRequest $request): JsonResponse
    {
        $perPage = $this->canteenFilters();

        $validated = $request->validated();

        $result = $this->canteenService->getGeneralCanteenIncomeHistory(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage
        );

        return HandleServiceResponse::format($result);
    }

    public function canteenIncomeHistoryPerCanteenId(HistoryRequest $request, int $canteenId): JsonResponse
    {
        $perPage = $this->canteenFilters();

        $validated = $request->validated();

        $result = $this->canteenService->getCanteenIncomeHistory(
            $canteenId,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
            $perPage
        );

        return HandleServiceResponse::format($result);
    }
}
