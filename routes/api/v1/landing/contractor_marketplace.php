<?php

use App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing\MarketplaceCategoryController;
use App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing\MarketplaceOfferInboxController;
use App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing\MarketplaceProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('contractor-marketplace')->name('contractor-marketplace.')->group(function (): void {
    Route::get('/categories', [MarketplaceCategoryController::class, 'index'])
        ->middleware('authorize:contractor_marketplace.categories.view')
        ->name('categories.index');
    Route::get('/profile', [MarketplaceProfileController::class, 'show'])
        ->middleware('authorize:contractor_marketplace.profile.view')
        ->name('profile.show');
    Route::put('/profile', [MarketplaceProfileController::class, 'update'])
        ->middleware('authorize:contractor_marketplace.profile.edit')
        ->name('profile.update');
    Route::post('/profile/publish', [MarketplaceProfileController::class, 'publish'])
        ->middleware('authorize:contractor_marketplace.profile.publish')
        ->name('profile.publish');
    Route::post('/profile/pause', [MarketplaceProfileController::class, 'pause'])
        ->middleware('authorize:contractor_marketplace.profile.publish')
        ->name('profile.pause');
    Route::post('/profile/documents', [MarketplaceProfileController::class, 'uploadDocument'])
        ->middleware('authorize:contractor_marketplace.profile.edit')
        ->name('profile.documents.store');
    Route::delete('/profile/documents/{document}', [MarketplaceProfileController::class, 'deleteDocument'])
        ->middleware('authorize:contractor_marketplace.profile.edit')
        ->name('profile.documents.destroy');
    Route::get('/offers', [MarketplaceOfferInboxController::class, 'index'])
        ->middleware('authorize:contractor_marketplace.offers.view')
        ->name('offers.index');
    Route::get('/offers/{offer}', [MarketplaceOfferInboxController::class, 'show'])
        ->middleware('authorize:contractor_marketplace.offers.view')
        ->name('offers.show');
    Route::post('/offers/{offer}/view', [MarketplaceOfferInboxController::class, 'view'])
        ->middleware('authorize:contractor_marketplace.offers.respond')
        ->name('offers.view');
    Route::post('/offers/{offer}/accept', [MarketplaceOfferInboxController::class, 'accept'])
        ->middleware('authorize:contractor_marketplace.offers.respond')
        ->name('offers.accept');
    Route::post('/offers/{offer}/decline', [MarketplaceOfferInboxController::class, 'decline'])
        ->middleware('authorize:contractor_marketplace.offers.respond')
        ->name('offers.decline');
});
