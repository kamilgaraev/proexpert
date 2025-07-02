<?php

use Illuminate\Support\Facades\Route;

// Роуты для холдинговых поддоменов (исключая служебные)
Route::domain('{holding}.' . config('app.domain', 'prohelper.pro'))
    ->middleware(['holding.subdomain'])
    ->where(['holding' => '^(?!www|lk|api|admin|mail|ftp).*$'])
    ->group(function () {
        require __DIR__ . '/holding.php';
    });

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json(['message' => 'Please use API endpoints for authentication'], 401);
})->name('login');

// Простой тест без контроллера
Route::get('/simple-test', function () {
    return response("# Simple test\ntest_metric 1\n", 200, [
        'Content-Type' => 'text/plain; charset=utf-8'
    ]);
});

Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);
Route::get('/test-metrics', [App\Http\Controllers\MetricsController::class, 'test']);

Route::get('/docs/{type?}', function (string $type = 'lk') {
    $allowed = ['lk', 'admin', 'mobile'];
    $type = in_array($type, $allowed) ? $type : 'lk';
    $path = public_path("docs/{$type}_api.html");
    if (!file_exists($path)) {
        abort(404, 'Документация не найдена');
    }
    return response()->file($path);
});
