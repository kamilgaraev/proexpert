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

    // 1) Сначала пробуем отдать готовый статический HTML из public/docs
    $candidatePaths = [
        public_path("docs/{$type}_api.html"),
        public_path("docs/{$type}/index.html"),
        public_path("docs/{$type}/api.html"),
        public_path('docs/api.html'), // общий fallback, если он есть
    ];

    foreach ($candidatePaths as $path) {
        if (file_exists($path)) {
            return response()->file($path);
        }
    }

    // 2) Если статического HTML нет — рендерим Redoc на лету из docs/openapi/{type}/openapi.yaml
    $yamlPath = base_path("docs/openapi/{$type}/openapi.yaml");
    if (file_exists($yamlPath)) {
        $specUrl = url("/docs-src/{$type}/openapi.yaml");
        $html = "<!DOCTYPE html><html lang=\"ru\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>API Docs - {$type}</title><style>body{margin:0;padding:0;} .wrapper{height:100vh;}</style></head><body><redoc spec-url=\"{$specUrl}\" class=\"wrapper\"></redoc><script src=\"https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js\"></script></body></html>";
        return response($html, 200)->header('Content-Type', 'text/html');
    }

    abort(404, 'Документация не найдена');
});

// Сервисный роут для отдачи YAML-спецификаций из репозитория
Route::get('/docs-src/{type}/openapi.yaml', function (string $type) {
    $allowed = ['lk', 'admin', 'mobile', 'landing_admin'];
    abort_unless(in_array($type, $allowed), 404);
    $yamlPath = base_path("docs/openapi/{$type}/openapi.yaml");
    abort_unless(file_exists($yamlPath), 404);
    return response()->file($yamlPath, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
});
