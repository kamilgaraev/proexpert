<?php

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterReadinessService;
use App\Http\Responses\LandingResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/ready', function (ImmutableAuditWriterReadinessService $readiness) {
    $status = $readiness->status(DB::connection(), (string) config('legal_archive.audit_writer_secret', ''));

    return response()->json($status, $status['ready'] ? 200 : 503);
});

// Роуты для холдинговых поддоменов (исключая служебные)
// Например: stroitelnyj-holding-alfa.1мост.рф
Route::domain('{holding}.'.config('app.domain', 'xn--1-xtbgmf.xn--p1ai'))
    ->middleware(['holding.subdomain'])
    ->where(['holding' => '^(?!www|lk|api|admin|mail|ftp).*$'])
    ->group(base_path('routes/subdomain/holding.php'));

Route::get('/', function () {
    return view('welcome');
});

Route::get('/release.json', function () {
    return response()->file('/etc/most/release.json', [
        'Cache-Control' => 'no-store, max-age=0',
        'Content-Type' => 'application/json',
    ]);
});

Route::get('/login', function () {
    return LandingResponse::error(trans_message('auth.token_missing'), 401);
})->name('login');

Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);
