<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AgentDownloadController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchScopeController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MergeAiController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProductGroupController;
use App\Http\Controllers\ReconcileController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\PrintPreferences;
use App\Support\AiProviders;
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
    Route::get('stock/item', [StockController::class, 'item'])->name('stock.item');
    Route::middleware([PrintPreferences::class, 'throttle:search'])->group(function () {
        Route::get('stock/print', [StockController::class, 'printList'])->name('stock.print');
        Route::get('stock/item/print', [StockController::class, 'printItem'])->name('stock.print-item');
    });
    // barre Ctrl+K (partials/palette) : articles qui correspondent, dans les branches affichees
    Route::get('palette', [StockController::class, 'palette'])->name('palette')->middleware('throttle:search');
    // selecteur de branche de la barre du haut (App\Support\BranchScope) ; un POST comme les boutons de langue
    Route::post('branch-scope', BranchScopeController::class)->name('branch-scope');
    Route::post('refresh', [StockController::class, 'refresh'])->name('stock.refresh')->middleware(['can:stock.refresh', 'throttle:refresh']);

    // bons de vente et d'achat, clients et fournisseurs (agent 1.0.7) : seulement les branches dont le compte voit les ventes (BranchScope::salesIds)
    Route::middleware('can:sales.view')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard.index')->middleware('throttle:search');
        Route::get('dashboard/calendar', [DashboardController::class, 'calendar'])->name('dashboard.calendar')->middleware('throttle:search');
        Route::get('sales', [DocumentController::class, 'sales'])->name('sales.index')->middleware('throttle:search');
        Route::get('sales/{document}', [DocumentController::class, 'sale'])->name('sales.show')->whereNumber('document');
        Route::get('purchases', [DocumentController::class, 'purchases'])->name('purchases.index')->middleware('throttle:search');
        Route::get('purchases/{document}', [DocumentController::class, 'purchase'])->name('purchases.show')->whereNumber('document');
        Route::get('customers', [PartnerController::class, 'customers'])->name('customers.index')->middleware('throttle:search');
        Route::get('customers/{partner}', [PartnerController::class, 'customer'])->name('customers.show')->whereNumber('partner');
        Route::get('customers/{partner}/records', [PartnerController::class, 'customerRecords'])->name('customers.records')->whereNumber('partner')->middleware('throttle:search');
        Route::get('suppliers', [PartnerController::class, 'suppliers'])->name('suppliers.index')->middleware('throttle:search');
        Route::get('suppliers/{partner}', [PartnerController::class, 'supplier'])->name('suppliers.show')->whereNumber('partner');
        Route::get('suppliers/{partner}/records', [PartnerController::class, 'supplierRecords'])->name('suppliers.records')->whereNumber('partner')->middleware('throttle:search');

        // pages d'impression (layouts/print) : memes branches et memes permissions que les pages d'ou elles viennent
        Route::middleware([PrintPreferences::class, 'throttle:search'])->group(function () {
            Route::get('sales/print', [DocumentController::class, 'salesPrint'])->name('sales.print');
            Route::get('sales/{document}/print', [DocumentController::class, 'salePrint'])->name('sales.print-bon')->whereNumber('document');
            Route::get('purchases/print', [DocumentController::class, 'purchasesPrint'])->name('purchases.print');
            Route::get('purchases/{document}/print', [DocumentController::class, 'purchasePrint'])->name('purchases.print-bon')->whereNumber('document');
            Route::get('customers/print', [PartnerController::class, 'customersPrint'])->name('customers.print');
            Route::get('customers/payments/print', [PartnerController::class, 'customerPaymentsPrint'])->name('customers.print-payments');
            Route::get('customers/{partner}/print', [PartnerController::class, 'customerPrint'])->name('customers.print-card')->whereNumber('partner');
            Route::get('suppliers/print', [PartnerController::class, 'suppliersPrint'])->name('suppliers.print');
            Route::get('suppliers/payments/print', [PartnerController::class, 'supplierPaymentsPrint'])->name('suppliers.print-payments');
            Route::get('suppliers/{partner}/print', [PartnerController::class, 'supplierPrint'])->name('suppliers.print-card')->whereNumber('partner');
            Route::get('cash/print', [CashController::class, 'print'])->name('cash.print')->middleware('can:cash.view');
        });

        // "المطابقة" : les comptes de deux branches qui commercent (Reconciliation), et l'IA qui les explique
        Route::middleware('can:reconcile.use')->group(function () {
            Route::get('reconcile', [ReconcileController::class, 'index'])->name('reconcile.index')->middleware('throttle:search');
            Route::get('reconcile/partners', [ReconcileController::class, 'partners'])->name('reconcile.partners')->middleware('throttle:search');
            Route::post('reconcile/chat', [ReconcileController::class, 'chat'])->name('reconcile.chat')->middleware('throttle:refresh');
        });
    });

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index')->middleware('can:branches.view');
    Route::middleware('can:branches.sync')->group(function () {
        Route::post('branches/{branch}/sync', [BranchController::class, 'sync'])->name('branches.sync')->middleware('throttle:refresh');
        Route::get('branches/{branch}/sync/{job}', [BranchController::class, 'syncStatus'])->name('branches.sync-status');
    });
    Route::middleware('can:branches.manage')->group(function () {
        Route::post('branches/install-code', [BranchController::class, 'installCode'])->name('branches.install-code');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('branches/{branch}/main', [BranchController::class, 'main'])->name('branches.main');
        Route::get('branches/{branch}/print-header', [BranchController::class, 'printHeader'])->name('branches.print-header');
        Route::put('branches/{branch}/print-header', [BranchController::class, 'savePrintHeader']);
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });
    Route::put('branches/{branch}/access', [BranchController::class, 'access'])->name('branches.access')->middleware('can:manage-branch-access');

    Route::middleware('can:merge.manage')->group(function () {
        Route::get('merge', [StockController::class, 'merge'])->name('merge.index')->middleware('throttle:search');
        Route::post('merge', [ProductGroupController::class, 'store'])->name('merge.store');
        Route::delete('merge', [ProductGroupController::class, 'destroy'])->name('merge.destroy');
        // propositions de l'IA (MergeScanner) : seulement des propositions, chaque fusion est un clic
        Route::get('merge/ai', [MergeAiController::class, 'index'])->name('merge.ai');
        Route::post('merge/ai', [MergeAiController::class, 'start'])->name('merge.ai.start')->middleware('throttle:refresh');
        Route::post('merge/ai/{scan}/step', [MergeAiController::class, 'step'])->name('merge.ai.step')->whereNumber('scan')->middleware('throttle:refresh');
        Route::post('merge/ai/{scan}/cancel', [MergeAiController::class, 'cancel'])->name('merge.ai.cancel')->whereNumber('scan');
        Route::post('merge/ai/{scan}/resume', [MergeAiController::class, 'resume'])->name('merge.ai.resume')->whereNumber('scan');
        Route::post('merge/ai/{scan}/merge', [MergeAiController::class, 'merge'])->name('merge.ai.merge')->whereNumber('scan');
        Route::post('merge/ai/{scan}/{suggestion}/dismiss', [MergeAiController::class, 'dismiss'])->name('merge.ai.dismiss')->whereNumber(['scan', 'suggestion']);
        Route::post('merge/ai/{scan}/{suggestion}/reopen', [MergeAiController::class, 'reopen'])->name('merge.ai.reopen')->whereNumber(['scan', 'suggestion']);
    });

    Route::middleware('can:agent.download')->group(function () {
        Route::get('agent/download', [AgentDownloadController::class, 'zip'])->name('agent.download');
        Route::get('agent/guide', [AgentDownloadController::class, 'guide'])->name('agent.guide');
    });
});

// Comptes : selon le role (App\Support\Roles), jamais une case (ce serait se donner tous les droits).
// UserController verifie en plus que chaque compte touche est un compte que l'auteur gere.
// Journal des changements (comptes, branches) : le developpeur seulement.
Route::get('activity', [ActivityController::class, 'index'])->name('activity.index')->middleware(['auth', 'can:view-activity']);

// Lien avec Claude, ChatGPT et Gemini : le developpeur seulement (les cles d'API paient sur son compte chez le fournisseur).
// Enregistrer une cle et la tester appellent le fournisseur depuis le serveur : limiteur "refresh".
Route::middleware(['auth', 'can:manage-ai'])->whereIn('provider', array_keys(AiProviders::PROVIDERS))->group(function () {
    Route::get('settings/ai', [AiSettingsController::class, 'index'])->name('ai.index');
    Route::middleware('throttle:refresh')->group(function () {
        Route::put('settings/ai/{provider}', [AiSettingsController::class, 'update'])->name('ai.update');
        Route::post('settings/ai/{provider}/test', [AiSettingsController::class, 'test'])->name('ai.test');
        Route::delete('settings/ai/{provider}/key', [AiSettingsController::class, 'destroy'])->name('ai.destroy');
    });
});

Route::middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
