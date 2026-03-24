<?php

use App\Http\Responses\LandingResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:api', 'module:integrations'])->group(function () {
    Route::prefix('integrations')->group(function () {
        Route::get('/', function () {
            return LandingResponse::success(null, trans_message('integrations.index_message'));
        });
        
        Route::prefix('1c')->group(function () {
            Route::post('/sync', function () {
                return LandingResponse::success(null, trans_message('integrations.onec_sync_message'));
            });
        });
        
        Route::prefix('webhooks')->group(function () {
            Route::get('/', function () {
                return LandingResponse::success(null, trans_message('integrations.webhooks_index_message'));
            });
            
            Route::post('/', function () {
                return LandingResponse::success(null, trans_message('integrations.webhooks_store_message'));
            });
        });
    });
});
