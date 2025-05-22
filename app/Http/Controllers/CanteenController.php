<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Http\Requests\AmountRequest;
use App\Services\CanteenService;
use Illuminate\Http\JsonResponse;

class CanteenController extends Controller
{
    protected $canteenService;

    public function __construct(CanteenService $canteenService)
    {
        $this->canteenService = $canteenService;
    }

    public function initialFund(AmountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = $request->input('auth_user')['id'];

        $result = $this->canteenService->inputInitialFund($userId, $validated['amount']);

        return HandleServiceResponse::format($result);
    }
}
