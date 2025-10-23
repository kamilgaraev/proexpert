<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ActFileController;

Route::prefix('act-files')->name('act_files.')->group(function () {
    Route::get('/', [ActFileController::class, 'index'])->name('index');
    Route::get('{id}', [ActFileController::class, 'download'])->name('download');
    Route::delete('{id}', [ActFileController::class, 'destroy'])->name('destroy');
});

