<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentDownloadController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProductGroupController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Langue de l'interface, pour tous (page de connexion comprise).
Route::post('locale', LocaleController::class)->name('locale');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
});

// Tous les comptes (employes compris) : ecran des applications, rechercher dans le stock et gerer son propre compte.
// Le reste depend des permissions cochees par un administrateur (App\Support\Permissions, middleware "can:").
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');
    // icone "الإعدادات" : les comptes pour qui en gere (developer, owner, directeur de branche), son propre compte pour les autres
    Route::get('settings', fn () => redirect()->route(auth()->user()->managesAccounts() ? 'users.index' : 'account.edit'))->name('settings');

    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('stock', [StockController::class, 'index'])->name('stock.index')->middleware('throttle:search');
    Route::get('product', [StockController::class, 'product'])->name('stock.product');
    Route::post('refresh', [StockController::class, 'refresh'])->name('stock.refresh')->middleware(['can:stock.refresh', 'throttle:refresh']);

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index')->middleware('can:branches.view');
    Route::middleware('can:branches.sync')->group(function () {
        Route::post('branches/{branch}/sync', [BranchController::class, 'sync'])->name('branches.sync')->middleware('throttle:refresh');
        Route::get('branches/{branch}/sync/{job}', [BranchController::class, 'syncStatus'])->name('branches.sync-status');
    });
    Route::middleware('can:branches.manage')->group(function () {
        Route::post('branches/install-code', [BranchController::class, 'installCode'])->name('branches.install-code');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('branches/{branch}/main', [BranchController::class, 'main'])->name('branches.main');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });
    Route::put('branches/{branch}/access', [BranchController::class, 'access'])->name('branches.access')->middleware('can:manage-branch-access');

    Route::middleware('can:merge.manage')->group(function () {
        Route::get('merge', [StockController::class, 'merge'])->name('merge.index')->middleware('throttle:search');
        Route::post('merge', [ProductGroupController::class, 'store'])->name('merge.store');
        Route::delete('merge', [ProductGroupController::class, 'destroy'])->name('merge.destroy');
    });

    Route::middleware('can:agent.download')->group(function () {
        Route::get('agent/download', [AgentDownloadController::class, 'zip'])->name('agent.download');
        Route::get('agent/guide', [AgentDownloadController::class, 'guide'])->name('agent.guide');
    });
});

// Comptes : selon le role (App\Support\Roles), jamais une case (ce serait se donner tous les droits).
// UserController verifie en plus que chaque compte touche est un compte que l'auteur gere.
Route::middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
