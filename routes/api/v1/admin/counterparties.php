<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CounterpartyController;
use Illuminate\Support\Facades\Route;

Route::get('counterparties/search', [CounterpartyController::class, 'search'])->name('counterparties.search');
Route::apiResource('counterparties', CounterpartyController::class);
