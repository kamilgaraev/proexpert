<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Public\ContactFormController;

Route::prefix('contact')->group(function () {
    Route::post('/', [ContactFormController::class, 'store'])->name('contact.store');
});
