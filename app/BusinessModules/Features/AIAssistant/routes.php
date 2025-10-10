<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;

Route::middleware(['auth:api', 'organization.context'])
    ->prefix('api/v1/ai-assistant')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat']);
        Route::get('/conversations', [AIAssistantController::class, 'conversations']);
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation']);
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation']);
        Route::get('/usage', [AIAssistantController::class, 'usage']);
    });

