<?php

namespace App\Http\Controllers;

use App\Helpers\HandleServiceResponse;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function transactionReport(): JsonResponse
    {
        $range = request()->query('range') ?? null;
        $canteenId = request()->query('canteen_id') ?? null;

        $result = $this->reportService->generateTransactionReport($range, $canteenId);

        return HandleServiceResponse::format($result);
    }

    public function financialReport(): JsonResponse
    {
        $range = request()->query('range') ?? null;
        $canteenId = request()->query('canteen_id') ?? null;

        $result = $this->reportService->generateFinancialSummary($range, $canteenId);

        return HandleServiceResponse::format($result);
    }
}
