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


Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);

Route::get('/docs/{type?}', function (string $type = 'lk') {
    $allowed = ['lk', 'admin', 'mobile', 'landing_admin'];
    if (!in_array($type, $allowed)) {
        $type = 'lk';
    }

    // Поддержка алиаса /docs/admin -> /docs/landing_admin (как в публичной папке)
    $typeAliases = [
        'admin' => ['admin', 'landing_admin'],
    ];

    $searchTypes = $typeAliases[$type] ?? [$type];

    // Ищем первый существующий вариант файла документации по списку типов
    $candidatePaths = [];
    foreach ($searchTypes as $t) {
        $candidatePaths[] = public_path("docs/{$t}_api.html");
        $candidatePaths[] = public_path("docs/{$t}/index.html");
        $candidatePaths[] = public_path("docs/{$t}/api.html");
    }
    // Общий fallback
    $candidatePaths[] = public_path('docs/api.html');

    foreach ($candidatePaths as $path) {
        if (file_exists($path)) {
            return response()->file($path);
        }
    }

    abort(404, 'Документация не найдена');
});
