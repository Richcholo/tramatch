<?php

use App\Http\Controllers\Admin\DestinationController as AdminDestinationController;
use App\Http\Controllers\Admin\ProposalController as AdminProposalController;
use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SwipeDiscoveryController;
use Illuminate\Support\Facades\Route;
use App\Models\Destination;


Route::get('/', function () {
    $featuredDestinations = Destination::query()
        ->with('tags')
        ->where('is_active', true)
        ->latest()
        ->take(3)
        ->get();

    return view('welcome', compact('featuredDestinations'));
});

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::get('/destinations', [
    DestinationController::class,
    'index',
])->name('destinations.index');

Route::get('/destinations/{destination:slug}', [
    DestinationController::class,
    'show',
])->name('destinations.show');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [
        ProfileController::class,
        'edit',
    ])->name('profile.edit');

    Route::patch('/profile', [
        ProfileController::class,
        'update',
    ])->name('profile.update');

    Route::delete('/profile', [
        ProfileController::class,
        'destroy',
    ])->name('profile.destroy');

    Route::get('/preferences', [
        PreferenceController::class,
        'edit',
    ])->name('preferences.edit');

    Route::put('/preferences', [
        PreferenceController::class,
        'update',
    ])->name('preferences.update');

    Route::get('/recommendations', RecommendationController::class)
        ->name('recommendations.index');

    Route::get('/discover', [
        SwipeDiscoveryController::class,
        'index',
    ])->name('discover.index');

    Route::post('/discover/swipes', [
        SwipeDiscoveryController::class,
        'store',
    ])->name('discover.swipes.store');

    Route::post('/discover/reset', [
        SwipeDiscoveryController::class,
        'reset',
    ])->name('discover.reset');

    Route::post('/destinations/{destination:slug}/reviews', [
        ReviewController::class,
        'store',
    ])->name('reviews.store');

    Route::resource('itineraries', ItineraryController::class)
        ->only([
            'index',
            'create',
            'store',
            'show',
            'destroy',
        ]);

    Route::patch('/itineraries/{itinerary}/complete', [
        ItineraryController::class,
        'complete',
    ])->name('itineraries.complete');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminController::class)
            ->name('dashboard');

        Route::get('/sources', [AdminSourceController::class, 'index'])
            ->name('sources.index');

        Route::get('/sources/status', [AdminSourceController::class, 'status'])
            ->name('sources.status');

        Route::get('/sources/{source}', [AdminSourceController::class, 'show'])
            ->name('sources.show');

        Route::patch('/sources/{source}', [AdminSourceController::class, 'update'])
            ->name('sources.update');

        Route::post('/sources/bulk-crawl', [AdminSourceController::class, 'bulkCrawl'])
            ->name('sources.bulk-crawl');

        Route::post('/sources/{source}/crawl', [AdminSourceController::class, 'crawl'])
            ->name('sources.crawl');

        Route::get('/proposals', [AdminProposalController::class, 'index'])
            ->name('proposals.index');

        Route::patch('/proposals/bulk', [AdminProposalController::class, 'bulk'])
            ->name('proposals.bulk');

        Route::patch('/proposals/{proposal}/approve', [AdminProposalController::class, 'approve'])
            ->name('proposals.approve');

        Route::patch('/proposals/{proposal}/reject', [AdminProposalController::class, 'reject'])
            ->name('proposals.reject');

        Route::patch(
            '/destinations/{destination}/archive',
            [
                AdminDestinationController::class,
                'archive',
            ]
        )->name('destinations.archive');

        Route::patch(
            '/destinations/{destination}/restore',
            [
                AdminDestinationController::class,
                'restore',
            ]
        )->name('destinations.restore');

        Route::resource(
            'destinations',
            AdminDestinationController::class
        )->except(['show']);
    });

require __DIR__.'/auth.php';