<?php

use App\Http\Controllers\Api\AiProposalController;
use App\Http\Controllers\Api\AiProjectController;
use App\Http\Controllers\Api\AiMcpController;
use App\Models\AiAccessKey;
use Illuminate\Support\Facades\Route;

Route::post('/v1/ai/proposals', [AiProposalController::class, 'store'])
    ->middleware(['throttle:30,1', 'ai-key:'.AiAccessKey::SCOPE_PROPOSALS_CREATE, 'ai-enabled'])
    ->name('api.ai.proposals.store');

Route::get('/v1/ai/projects', [AiProjectController::class, 'index'])
    ->middleware(['throttle:60,1', 'ai-key:'.AiAccessKey::SCOPE_PROJECTS_READ, 'ai-enabled'])
    ->name('api.ai.projects.index');
Route::get('/v1/ai/projects/{projectPublicId}', [AiProjectController::class, 'show'])
    ->middleware(['throttle:60,1', 'ai-key:'.AiAccessKey::SCOPE_PROJECTS_READ, 'ai-enabled'])
    ->name('api.ai.projects.show');

Route::get('/mcp/rise-gate-os', [AiMcpController::class, 'info'])
    ->middleware(['throttle:120,1', 'ai-key', 'ai-enabled']);
Route::post('/mcp/rise-gate-os', [AiMcpController::class, 'handle'])
    ->middleware(['throttle:120,1', 'ai-key', 'ai-enabled'])
    ->name('api.ai.mcp');
