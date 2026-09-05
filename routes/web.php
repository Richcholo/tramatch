<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\SwipeDiscoveryController;
use App\Http\Controllers\ItineraryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::get('/destinations', [DestinationController::class, 'index'])
    ->name('destinations.index');

Route::get('/destinations/{destination:slug}', [DestinationController::class, 'show'])
    ->name('destinations.show');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    Route::get('/preferences', [PreferenceController::class, 'edit'])
        ->name('preferences.edit');

    Route::put('/preferences', [PreferenceController::class, 'update'])
        ->name('preferences.update');

    Route::get('/recommendations', RecommendationController::class)
        ->name('recommendations.index');

    Route::get('/discover', [SwipeDiscoveryController::class, 'index'])
    ->name('discover.index');

    Route::post('/discover/swipes', [SwipeDiscoveryController::class, 'store'])
        ->name('discover.swipes.store');
        
    Route::post('/discover/reset', [SwipeDiscoveryController::class, 'reset'])
        ->name('discover.reset');

    Route::resource('itineraries', ItineraryController::class)
    ->only(['index', 'create', 'store', 'show', 'destroy']);

    Route::patch('/itineraries/{itinerary}/complete', [ItineraryController::class, 'complete'])
    ->name('itineraries.complete');
});

require __DIR__.'/auth.php';