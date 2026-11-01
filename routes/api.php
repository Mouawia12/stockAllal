<?php

use App\Http\Controllers\Api\AgentController;
use Illuminate\Support\Facades\Route;

// Enregistrement d'un nouvel agent avec un code d'installation (sans token).
Route::post('agents/register', [AgentController::class, 'register'])->middleware('throttle:agent-register');

// Agents deja enregistres : Authorization: Bearer <token de la branche>.
Route::middleware(['throttle:agent', 'agent'])->prefix('agent')->group(function () {
    Route::get('ping', [AgentController::class, 'ping']);
    Route::post('sync', [AgentController::class, 'sync']);
    Route::get('pending', [AgentController::class, 'pending']);
    Route::post('result', [AgentController::class, 'result']);
});
