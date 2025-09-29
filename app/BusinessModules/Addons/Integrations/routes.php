<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:api', 'module:integrations'])->group(function () {
    Route::prefix('integrations')->group(function () {
        Route::get('/', function () {
            return response()->json(['message' => 'Integrations functionality']);
        });
        
        Route::prefix('1c')->group(function () {
            Route::post('/sync', function () {
                return response()->json(['message' => '1C integration sync']);
            });
        });
        
        Route::prefix('webhooks')->group(function () {
            Route::get('/', function () {
                return response()->json(['message' => 'Webhooks list']);
            });
            
            Route::post('/', function () {
                return response()->json(['message' => 'Create webhook']);
            });
        });
    });
});
