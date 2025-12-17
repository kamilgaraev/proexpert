<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\Geo\GeocodingController;

/*
|--------------------------------------------------------------------------
| Geocoding Routes
|--------------------------------------------------------------------------
|
| Routes for geocoding projects and managing geocoding status
|
*/

Route::middleware(['auth:api_admin'])->group(function () {
    // Geocoding status and statistics
    Route::get('/projects/geocoding-status', [GeocodingController::class, 'getStatistics'])
        ->name('projects.geocoding.status');
    
    // Batch geocoding
    Route::post('/projects/batch-geocode', [GeocodingController::class, 'batchGeocode'])
        ->name('projects.geocoding.batch');
    
    // Single project geocoding
    Route::post('/projects/{id}/geocode', [GeocodingController::class, 'geocodeProject'])
        ->name('projects.geocode');
});

