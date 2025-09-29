<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:api', 'module:data-export'])->group(function () {
    Route::prefix('data-export')->group(function () {
        Route::post('/export', function () {
            return response()->json(['message' => 'Data export functionality']);
        });
    });
});
