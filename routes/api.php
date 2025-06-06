<?php

use App\Http\Controllers\CanteenController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::post('/transaction/top-up', [TransactionController::class, 'topUp']);
    Route::get('/transaction/top-up/history', [TransactionController::class, 'topUpHistory']);

    Route::get('/transaction/balance-exchange/pending', [TransactionController::class, 'getPendingWithdrawalRequests']);
    Route::post('/transaction/balance-exchange/{requestId}/accept', [TransactionController::class, 'acceptCanteenBalanceExchange']);
    Route::post('/transaction/balance-exchange/{requestId}/reject', [TransactionController::class, 'rejectCanteenBalanceExchange']);
});

Route::middleware(['jwt.verify', 'role:siswa'])->group(function () {
    Route::get('/transaction/check-balance', [WalletController::class, 'checkBalance']);

    //Bisa get history "top up", "pembelian", "refund"
    Route::get('/transaction/personal-history', [TransactionController::class, 'personalTransactionHistory']);

    //Manajemen PIN
    Route::post('/wallet/pin', [WalletController::class, 'addPin']);
    Route::put('/wallet/pin', [WalletController::class, 'updatePin']);
});

Route::middleware(['jwt.verify', 'role:penjaga kantin'])->group(function () {
    //Manajemen kantin
    Route::post('/canteen/open', [CanteenController::class, 'openCanteen']);
    Route::post('/canteen/initial-fund', [CanteenController::class, 'initialFund']);
    Route::post('/canteen/settle', [CanteenController::class, 'settleCanteen']);
    Route::post('/canteen/close', [CanteenController::class, 'closeCanteen']);

    Route::post('/transaction/purchase', [TransactionController::class, 'processPurchase']);

    //Bisa get history "pembelian", "refund", "pencairan"
    Route::get('/transaction/canteen-history', [TransactionController::class, 'canteenTransactionHistory']);

    Route::get('/canteen/initial-fund/history', [CanteenController::class, 'canteenInitialFundHistory']);
    Route::get('/canteen/income/history', [CanteenController::class, 'generalCanteenIncomeHistory']);
    Route::get('/canteen/{canteenId}/income/history', [CanteenController::class, 'canteenIncomeHistoryPerCanteenId']);

    Route::post('/transaction/{transactionId}/refund', [TransactionController::class, 'refundCanteenTransaction']);

    Route::post('/transaction/balance-exchange/request/{canteenId}', [TransactionController::class, 'requestCanteenBalanceExchange']);
});

Route::middleware(['jwt.verify', 'role:admin,petinggi sekolah'])->group(function () {
    Route::get('/report/transaction', [ReportController::class, 'transactionReport']);
    Route::get('/report/financial', [ReportController::class, 'financialReport']);
});

Route::middleware('jwt.verify', 'role:admin,penjaga kantin')->group(function(){
    Route::get('/transaction/balance-exchange/{withdrawalId}', [TransactionController::class, 'withdrawalDetailById']);
});

Route::middleware(['jwt.verify', 'role:siswa,penjaga kantin'])->group(function () {
    Route::get('/transaction/purchase/{transactionId}', [TransactionController::class, 'purchaseDetailById']);
    Route::get('/transaction/refund/{refundTransactionId}', [TransactionController::class, 'refundDetailById']);
});
