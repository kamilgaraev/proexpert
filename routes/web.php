<?php

use Illuminate\Support\Facades\Route;

// Роуты для холдинговых поддоменов (исключая служебные)
// Например: stroitelnyj-holding-alfa.prohelper.pro
Route::domain('{holding}.' . config('app.domain', 'prohelper.pro'))
    ->middleware(['holding.subdomain'])
    ->where(['holding' => '^(?!www|lk|api|admin|mail|ftp).*$'])
    ->group(base_path('routes/subdomain/holding.php'));

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json(['message' => 'Please use API endpoints for authentication'], 401);
})->name('login');


Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);


