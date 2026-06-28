<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileKnowledgeHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('knowledge-hub')
    ->name('knowledgeHub.')
    ->group(function (): void {
        Route::get('/overview', [MobileKnowledgeHubController::class, 'overview'])->name('overview');
        Route::get('/tree', [MobileKnowledgeHubController::class, 'tree'])->name('tree');
        Route::get('/search', [MobileKnowledgeHubController::class, 'search'])->name('search');
        Route::get('/context', [MobileKnowledgeHubController::class, 'context'])->name('context');
        Route::post('/feedback', [MobileKnowledgeHubController::class, 'feedback'])->name('feedback');
        Route::get('/articles', [MobileKnowledgeHubController::class, 'articles'])->name('articles.index');
        Route::get('/articles/{slug}', [MobileKnowledgeHubController::class, 'article'])->name('articles.show');
    });
