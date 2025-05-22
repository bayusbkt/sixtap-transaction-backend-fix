<?php

use App\Http\Controllers\CanteenController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::post('/transaction/top-up', [TransactionController::class, 'topUp']);
    Route::get('/transaction/top-up/history', [TransactionController::class, 'topUpHistory']);
});

Route::middleware(['jwt.verify', 'role:siswa'])->group(function () {
    Route::post('/transaction/check-balance/{userId}', [TransactionController::class, 'checkBalance']);
    Route::post('/wallet/pin/{userId}', [TransactionController::class, 'addPin']);
    Route::put('/wallet/pin/{userId}', [TransactionController::class, 'updatePin']);
});

Route::middleware(['jwt.verify', 'role:penjaga kantin'])->group(function () {
   Route::post('/canteen/initial-fund', [CanteenController::class, 'initialFund']);
});
