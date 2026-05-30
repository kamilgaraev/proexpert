<?php

use App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin\MarketplaceCategoryController;
use App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin\MarketplaceHiringOfferController;
use App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin\MarketplaceProfileController;
use App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin\MarketplaceSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('contractor-marketplace')->name('contractor-marketplace.')->group(function (): void {
    Route::get('/categories', [MarketplaceCategoryController::class, 'index'])
        ->middleware('authorize:contractor_marketplace.categories.view')
        ->name('categories.index');
    Route::get('/search', [MarketplaceSearchController::class, 'index'])
        ->middleware('authorize:contractor_marketplace.search.view')
        ->name('search.index');
    Route::get('/profiles/{profile}', [MarketplaceProfileController::class, 'show'])
        ->middleware('authorize:contractor_marketplace.profile.view')
        ->name('profiles.show');
    Route::get('/offers', [MarketplaceHiringOfferController::class, 'index'])
        ->middleware('authorize:contractor_marketplace.offers.view')
        ->name('offers.index');
    Route::post('/offers', [MarketplaceHiringOfferController::class, 'store'])
        ->middleware('authorize:contractor_marketplace.offers.create')
        ->name('offers.store');
    Route::get('/offers/{offer}', [MarketplaceHiringOfferController::class, 'show'])
        ->middleware('authorize:contractor_marketplace.offers.view')
        ->name('offers.show');
    Route::post('/offers/{offer}/cancel', [MarketplaceHiringOfferController::class, 'cancel'])
        ->middleware('authorize:contractor_marketplace.offers.cancel')
        ->name('offers.cancel');
    Route::post('/offers/{offer}/review', [MarketplaceHiringOfferController::class, 'review'])
        ->middleware('authorize:contractor_marketplace.offers.review')
        ->name('offers.review');
});
