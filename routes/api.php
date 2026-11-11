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
    // agent 1.0.7 : ventes, achats, clients, fournisseurs, versements (agent/README.md)
    Route::post('digest', [AgentController::class, 'digest']);
    Route::post('records', [AgentController::class, 'records']);
    // agent 1.0.11 : une base archivee de Gescomm (un exercice clos), puis son contenu par digest / records + exercise_id
    Route::post('archive', [AgentController::class, 'archive']);
});
