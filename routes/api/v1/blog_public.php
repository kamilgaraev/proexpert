<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Blog\PublicBlogController;
use Illuminate\Support\Facades\Route;

Route::get('/articles', [PublicBlogController::class, 'articles'])->name('articles');
Route::get('/articles/popular', [PublicBlogController::class, 'popular'])->name('articles.popular');
Route::get('/articles/{article}/related', [PublicBlogController::class, 'related'])->name('articles.related')->whereNumber('article');
Route::get('/articles/{slug}', [PublicBlogController::class, 'article'])->name('articles.show');
Route::get('/categories', [PublicBlogController::class, 'categories'])->name('categories');
Route::get('/tags', [PublicBlogController::class, 'tags'])->name('tags');
Route::get('/search', [PublicBlogController::class, 'search'])->name('search');
Route::get('/preview/{article}', [PublicBlogController::class, 'preview'])->middleware('signed')->name('preview')->whereNumber('article');
