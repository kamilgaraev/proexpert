<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\PersonalFileController;

// Группа уже защищена middleware в RouteServiceProvider (auth:api_admin и т.д.)
Route::prefix('personal-files')->name('personal_files.')->group(function () {
    Route::get('/', [PersonalFileController::class, 'index'])->name('index');
    Route::post('folder', [PersonalFileController::class, 'createFolder'])->name('create_folder');
    Route::post('upload', [PersonalFileController::class, 'upload'])->name('upload');
    Route::delete('{id}', [PersonalFileController::class, 'destroy'])->name('destroy');
}); 