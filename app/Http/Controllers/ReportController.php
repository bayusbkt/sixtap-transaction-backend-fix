<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Http\Requests\DateRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function transactionReport(DateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->reportService->generateTransactionReport(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['specific_date'] ?? null,
            $validated['range'] ?? null,
        );

        return HandleServiceResponse::format($result);
    }
}
