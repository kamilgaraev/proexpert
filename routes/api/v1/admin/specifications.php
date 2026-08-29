<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\SpecificationController;

Route::get('specifications', [SpecificationController::class, 'index'])
    ->middleware('authorize:specifications.view');
Route::post('specifications', [SpecificationController::class, 'store'])
    ->middleware('authorize:specifications.create');
Route::get('specifications/{specification}', [SpecificationController::class, 'show'])
    ->middleware('authorize:specifications.view');
Route::match(['put', 'patch'], 'specifications/{specification}', [SpecificationController::class, 'update'])
    ->middleware('authorize:specifications.edit');
Route::delete('specifications/{specification}', [SpecificationController::class, 'destroy'])
    ->middleware('authorize:specifications.delete');
