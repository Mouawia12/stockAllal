<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [StockController::class, 'index'])->name('stock.index');
    Route::get('product', [StockController::class, 'product'])->name('stock.product');
    Route::post('refresh', [StockController::class, 'refresh'])->name('stock.refresh')->middleware('throttle:refresh');

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches/install-code', [BranchController::class, 'installCode'])->name('branches.install-code');
    Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
});
