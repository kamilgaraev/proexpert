<?php

declare(strict_types=1);

use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
    });
