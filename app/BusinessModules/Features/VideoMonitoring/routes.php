<?php

declare(strict_types=1);

use App\BusinessModules\Features\VideoMonitoring\Http\Controllers\VideoCameraController;
use Illuminate\Support\Facades\Route;

Route::middleware([
        'api',
        'auth:api_admin',
        'auth.jwt:api_admin',
        'organization.context',
        'authorize:admin.access',
        'interface:admin',
        'project.context',
    ])
    ->prefix('api/v1/admin/projects/{project}/video-monitoring')
    ->name('api.v1.admin.projects.video-monitoring.')
    ->group(function () {
        Route::get('/', [VideoCameraController::class, 'index'])->name('index');
        Route::post('/', [VideoCameraController::class, 'store'])->name('store');
        Route::post('/check', [VideoCameraController::class, 'check'])->name('check');
        Route::put('/{camera}', [VideoCameraController::class, 'update'])->name('update');
        Route::delete('/{camera}', [VideoCameraController::class, 'destroy'])->name('destroy');
    });
