<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\Blog\BlogArticleController;
use App\Http\Controllers\Api\V1\Landing\Blog\BlogCategoryController;
use App\Http\Controllers\Api\V1\Landing\Blog\BlogCommentController;
use App\Http\Controllers\Api\V1\Landing\Blog\BlogSeoController;
use App\Http\Controllers\Api\V1\Landing\Blog\BlogDashboardController;

Route::prefix('blog')->middleware(['auth:api_landing_admin'])->group(function () {
    
    // Дашборд блога
    Route::get('dashboard/overview', [BlogDashboardController::class, 'overview']);
    Route::get('dashboard/analytics', [BlogDashboardController::class, 'analytics']);
    Route::get('dashboard/quick-stats', [BlogDashboardController::class, 'quickStats']);

    // Статьи
    Route::apiResource('articles', BlogArticleController::class);
    Route::post('articles/{article}/publish', [BlogArticleController::class, 'publish']);
    Route::post('articles/{article}/schedule', [BlogArticleController::class, 'schedule']);
    Route::post('articles/{article}/archive', [BlogArticleController::class, 'archive']);
    Route::post('articles/{article}/duplicate', [BlogArticleController::class, 'duplicate']);
    Route::get('articles/{article}/seo-data', [BlogArticleController::class, 'generateSeoData']);
    Route::get('articles-scheduled', [BlogArticleController::class, 'getScheduled']);
    Route::get('articles-drafts', [BlogArticleController::class, 'getDrafts']);

    // Категории
    Route::apiResource('categories', BlogCategoryController::class);
    Route::post('categories/reorder', [BlogCategoryController::class, 'reorder']);

    // Комментарии
    Route::apiResource('comments', BlogCommentController::class)->except(['store', 'update']);
    Route::put('comments/{comment}/status', [BlogCommentController::class, 'updateStatus']);
    Route::post('comments/bulk-status', [BlogCommentController::class, 'bulkUpdateStatus']);
    Route::get('comments-pending', [BlogCommentController::class, 'getPending']);
    Route::get('comments-recent', [BlogCommentController::class, 'getRecent']);
    Route::get('comments/stats', [BlogCommentController::class, 'getStats']);

    // SEO
    Route::get('seo/settings', [BlogSeoController::class, 'getSettings']);
    Route::put('seo/settings', [BlogSeoController::class, 'updateSettings']);
    Route::get('seo/sitemap', [BlogSeoController::class, 'generateSitemap']);
    Route::get('seo/rss', [BlogSeoController::class, 'generateRssFeed']);
    Route::get('seo/robots', [BlogSeoController::class, 'generateRobotsTxt']);
    Route::get('seo/preview/sitemap', [BlogSeoController::class, 'previewSitemap']);
    Route::get('seo/preview/rss', [BlogSeoController::class, 'previewRssFeed']);
    Route::get('seo/preview/robots', [BlogSeoController::class, 'previewRobotsTxt']);
}); 