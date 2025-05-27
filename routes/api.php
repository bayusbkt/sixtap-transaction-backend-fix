<?php

use App\Http\Controllers\CanteenController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::post('/transaction/top-up', [TransactionController::class, 'topUp']);
    Route::get('/transaction/top-up/history', [TransactionController::class, 'topUpHistory']);
});

Route::middleware(['jwt.verify', 'role:siswa'])->group(function () {
    Route::post('/transaction/check-balance', [WalletController::class, 'checkBalance']);
    Route::post('/transaction/personal-history', [TransactionController::class, 'personalTransactionHistory']);

    Route::post('/wallet/pin', [WalletController::class, 'addPin']);
    Route::put('/wallet/pin', [WalletController::class, 'updatePin']);
});

Route::middleware(['jwt.verify', 'role:penjaga kantin'])->group(function () {
    Route::post('/canteen/open', [CanteenController::class, 'openCanteen']);
    Route::post('/canteen/initial-fund', [CanteenController::class, 'initialFund']);
    Route::post('/canteen/settle', [CanteenController::class, 'settleCanteen']);
    Route::post('/canteen/close', [CanteenController::class, 'closeCanteen']);

    Route::post('/transaction/purchase', [TransactionController::class, 'processTransaction']);
    Route::get('/transaction/purchase/history', [TransactionController::class, 'canteenTransactionHistory']);

    Route::post('/transaction/{transactionId}/refund', [TransactionController::class, 'refundCanteenTransaction']);
    Route::get('/transaction/refund/history', [TransactionController::class, 'refundTransactionHistory']);
});

Route::middleware(['jwt.verify', 'role:admin, petinggi sekolah'])->group(function() {
    Route::get('/report/transaction', [ReportController::class, 'transactionReport']);
    Route::get('/report/financial', [ReportController::class, 'financialReport']);
});
    