<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminKnowledgeHubController;
use Illuminate\Support\Facades\Route;

Route::prefix('knowledge-hub')
    ->name('knowledgeHub.')
    ->group(function (): void {
        Route::get('/overview', [AdminKnowledgeHubController::class, 'overview'])->name('overview');
        Route::get('/tree', [AdminKnowledgeHubController::class, 'tree'])->name('tree');
        Route::get('/search', [AdminKnowledgeHubController::class, 'search'])->name('search');
        Route::get('/context', [AdminKnowledgeHubController::class, 'context'])->name('context');
        Route::post('/feedback', [AdminKnowledgeHubController::class, 'feedback'])->name('feedback');
        Route::get('/articles', [AdminKnowledgeHubController::class, 'articles'])->name('articles.index');
        Route::get('/articles/{slug}', [AdminKnowledgeHubController::class, 'article'])->name('articles.show');
        Route::get('/changelog', [AdminKnowledgeHubController::class, 'changelog'])->name('changelog.index');
        Route::get('/changelog/{slug}', [AdminKnowledgeHubController::class, 'changelogEntry'])->name('changelog.show');
    });
