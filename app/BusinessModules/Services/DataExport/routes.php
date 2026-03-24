<?php

use App\Http\Responses\LandingResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:api', 'module:data-export'])->group(function () {
    Route::prefix('data-export')->group(function () {
        Route::post('/export', function () {
            return LandingResponse::success(null, trans_message('data_export.export_message'));
        });
    });
});
