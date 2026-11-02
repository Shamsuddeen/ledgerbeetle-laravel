<?php

use Illuminate\Support\Facades\Route;
use LedgerBeetle\Laravel\Http\Controllers\AccountController;
use LedgerBeetle\Laravel\Http\Controllers\ConversionController;
use LedgerBeetle\Laravel\Http\Controllers\HoldController;
use LedgerBeetle\Laravel\Http\Controllers\TransferController;

// {transfer}, {hold} and {conversion} bind implicitly from the controllers'
// type hints, which needs SubstituteBindings (part of the "api" group).

Route::prefix(config('ledger.api.prefix'))
    ->middleware(config('ledger.api.middleware'))
    ->name('ledger.')
    ->group(function () {
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{code}', [AccountController::class, 'show'])->name('accounts.show');
        Route::get('accounts/{code}/balance', [AccountController::class, 'balance'])->name('accounts.balance');
        Route::get('accounts/{code}/history', [AccountController::class, 'history'])->name('accounts.history');
        Route::get('accounts/{code}/transfers', [AccountController::class, 'transfers'])->name('accounts.transfers');

        Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::post('transfer-batches', [TransferController::class, 'batch'])->name('transfers.batch');
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('transfers/{transfer}/reversals', [TransferController::class, 'reverse'])->name('transfers.reverse');

        Route::post('holds', [HoldController::class, 'store'])->name('holds.store');
        Route::get('holds/{hold}', [HoldController::class, 'show'])->name('holds.show');
        Route::post('holds/{hold}/capture', [HoldController::class, 'capture'])->name('holds.capture');
        Route::post('holds/{hold}/release', [HoldController::class, 'release'])->name('holds.release');

        Route::post('conversions', [ConversionController::class, 'store'])->name('conversions.store');
        Route::get('conversions/{conversion}', [ConversionController::class, 'show'])->name('conversions.show');
        Route::post('conversions/{conversion}/reversal', [ConversionController::class, 'reverse'])->name('conversions.reverse');
    });
