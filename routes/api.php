<?php

use Illuminate\Support\Facades\Route;
use Platform\Infra\Http\Controllers\AgentController;

// Agent API (Bearer token): inventory, observations, findings.
Route::prefix('infra/agent')->middleware('auth:api')->group(function () {
    Route::get('/servers', [AgentController::class, 'servers'])->name('infra.api.agent.servers');
    Route::post('/servers', [AgentController::class, 'upsertServer'])->name('infra.api.agent.servers.upsert');
    Route::post('/servers/{id}/report', [AgentController::class, 'report'])->whereNumber('id')->name('infra.api.agent.servers.report');
    Route::get('/findings', [AgentController::class, 'findings'])->name('infra.api.agent.findings');
    Route::post('/findings/{id}/status', [AgentController::class, 'findingStatus'])->whereNumber('id')->name('infra.api.agent.findings.status');
});
