<?php

use App\Http\Controllers\CanteenController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

//API Admin
Route::middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::post('/transaction/top-up', [TransactionController::class, 'topUp']);
    Route::post('/transaction/top-up/history', [TransactionController::class, 'topUpHistory']);

    Route::get('/transaction/balance-exchange/pending', [TransactionController::class, 'getPendingWithdrawalRequests']);
    Route::post('/transaction/balance-exchange/history', [TransactionController::class,'withdrawalHistory']);
    Route::post('/transaction/balance-exchange/{requestId}/accept', [TransactionController::class, 'acceptCanteenBalanceExchange']);
    Route::post('/transaction/balance-exchange/{requestId}/reject', [TransactionController::class, 'rejectCanteenBalanceExchange']);
});

//API Siswa
Route::middleware(['jwt.verify', 'role:siswa'])->group(function () {
    Route::get('/transaction/check-balance', [WalletController::class, 'checkBalance']);

    //Bisa get history "top up", "pembelian", "refund"
    Route::post('/transaction/personal-history', [TransactionController::class, 'personalTransactionHistory']);

    //Manajemen PIN
    Route::post('/wallet/pin', [WalletController::class, 'addPin']);
    Route::put('/wallet/pin', [WalletController::class, 'updatePin']);
});

//API Penjaga Kantin
Route::middleware(['jwt.verify', 'role:penjaga kantin'])->group(function () {
    //Manajemen kantin
    Route::post('/canteen/open', [CanteenController::class, 'openCanteen']);
    Route::post('/canteen/initial-fund', [CanteenController::class, 'initialFund']);
    Route::post('/canteen/settle', [CanteenController::class, 'settleCanteen']);
    Route::post('/canteen/close', [CanteenController::class, 'closeCanteen']);

    Route::post('/transaction/purchase', [TransactionController::class, 'processPurchase']);

    //Bisa get history "pembelian", "refund", "pencairan"
    Route::post('/transaction/canteen-history', [TransactionController::class, 'canteenTransactionHistory']);

    Route::post('/canteen/initial-fund/history', [CanteenController::class, 'canteenInitialFundHistory']);
    Route::post('/canteen/income/history', [CanteenController::class, 'generalCanteenIncomeHistory']);
    Route::post('/canteen/{canteenId}/income/history', [CanteenController::class, 'canteenIncomeHistoryPerCanteenId']);

    Route::post('/transaction/{transactionId}/refund', [TransactionController::class, 'refundCanteenTransaction']);

    Route::post('/transaction/balance-exchange/request/{canteenId}', [TransactionController::class, 'requestCanteenBalanceExchange']);
});

//API Admin dan Petinggi Sekolah
Route::middleware(['jwt.verify', 'role:admin,petinggi sekolah'])->group(function () {
    Route::post('/report/transaction', [ReportController::class, 'transactionReport']);
});

//API Admin dan Penjaga Kantin
Route::middleware('jwt.verify', 'role:admin,penjaga kantin')->group(function(){
    Route::get('/transaction/balance-exchange/{withdrawalId}', [TransactionController::class, 'withdrawalDetailById']);
});

//API Siswa dan Penjaga Kantin
Route::middleware(['jwt.verify', 'role:siswa,penjaga kantin'])->group(function () {
    Route::get('/transaction/purchase/{transactionId}', [TransactionController::class, 'purchaseDetailById']);
    Route::get('/transaction/refund/{refundTransactionId}', [TransactionController::class, 'refundDetailById']);
});
