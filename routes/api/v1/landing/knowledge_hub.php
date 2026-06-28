<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Landing\KnowledgeHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->prefix('knowledge-hub')
    ->name('knowledgeHub.')
    ->group(function (): void {
        Route::get('/overview', [KnowledgeHubController::class, 'overview'])->name('overview');
        Route::get('/tree', [KnowledgeHubController::class, 'tree'])->name('tree');
        Route::get('/search', [KnowledgeHubController::class, 'search'])->name('search');
        Route::get('/context', [KnowledgeHubController::class, 'context'])->name('context');
        Route::post('/feedback', [KnowledgeHubController::class, 'feedback'])->name('feedback');
        Route::get('/articles', [KnowledgeHubController::class, 'articles'])->name('articles.index');
        Route::get('/articles/{slug}', [KnowledgeHubController::class, 'article'])->name('articles.show');
        Route::get('/changelog', [KnowledgeHubController::class, 'changelog'])->name('changelog.index');
        Route::get('/changelog/{slug}', [KnowledgeHubController::class, 'changelogEntry'])->name('changelog.show');
    });
