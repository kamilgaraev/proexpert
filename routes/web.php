<?php

use App\Http\Responses\LandingResponse;
use Illuminate\Support\Facades\Route;

// Роуты для холдинговых поддоменов (исключая служебные)
// Например: stroitelnyj-holding-alfa.1мост.рф
Route::domain('{holding}.' . config('app.domain', 'xn--1-xtbgmf.xn--p1ai'))
    ->middleware(['holding.subdomain'])
    ->where(['holding' => '^(?!www|lk|api|admin|mail|ftp).*$'])
    ->group(base_path('routes/subdomain/holding.php'));

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return LandingResponse::error(trans_message('auth.token_missing'), 401);
})->name('login');


Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);
