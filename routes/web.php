<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentDownloadController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProductGroupController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
});

// Tous les comptes (employes compris) : ecran des applications, consulter le stock et gerer son propre compte.
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');

    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('product', [StockController::class, 'product'])->name('stock.product');
    Route::post('refresh', [StockController::class, 'refresh'])->name('stock.refresh')->middleware('throttle:refresh');
});

// Administrateurs seulement : branches, fusion des codes, agent et comptes.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches/install-code', [BranchController::class, 'installCode'])->name('branches.install-code');
    Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::post('branches/{branch}/main', [BranchController::class, 'main'])->name('branches.main');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    Route::post('branches/{branch}/sync', [BranchController::class, 'sync'])->name('branches.sync')->middleware('throttle:refresh');
    Route::get('branches/{branch}/sync/{job}', [BranchController::class, 'syncStatus'])->name('branches.sync-status');

    Route::get('merge', [StockController::class, 'merge'])->name('merge.index');
    Route::post('merge', [ProductGroupController::class, 'store'])->name('merge.store');
    Route::delete('merge', [ProductGroupController::class, 'destroy'])->name('merge.destroy');

    Route::get('agent/download', [AgentDownloadController::class, 'zip'])->name('agent.download');
    Route::get('agent/guide', [AgentDownloadController::class, 'guide'])->name('agent.guide');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
