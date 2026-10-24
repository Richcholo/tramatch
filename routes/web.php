<?php

use App\Http\Controllers\Admin\DestinationController as AdminDestinationController;
use App\Http\Controllers\Admin\PlaceController as AdminPlaceController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
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

/*
 * QUICKHOP, AND IT IS DECLARED BEFORE `/{destination:slug}` ON PURPOSE.
 *
 * `/destinations/quickhop` matches the show route's own shape exactly, so
 * declared after it this route would never be reached: route-model binding
 * would resolve "quickhop" as a slug, fail to find it, and 404 -- and the
 * failure reads as a broken search box rather than as an ordering mistake.
 * The same trap `admin/sources/status` is declared before
 * `admin/sources/{source}` for.
 */
Route::get('/destinations/quickhop', [
    DestinationController::class,
    'quickhop',
])->name('destinations.quickhop');

Route::get('/destinations/{destination:slug}', [
    DestinationController::class,
    'show',
])->name('destinations.show');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [
        ProfileController::class,
        'show',
    ])->name('profile.show');

    Route::post('/editprofile/photo', [
        ProfileController::class,
        'updatePhoto',
    ])->name('profile.photo.update');

    Route::delete('/editprofile/photo', [
        ProfileController::class,
        'removePhoto',
    ])->name('profile.photo.destroy');

    Route::get('/editprofile', [
        ProfileController::class,
        'edit',
    ])->name('profile.edit');

    Route::patch('/editprofile', [
        ProfileController::class,
        'update',
    ])->name('profile.update');

    Route::delete('/editprofile', [
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

    Route::post('/discover/swipes/undo', [
        SwipeDiscoveryController::class,
        'undo',
    ])->name('discover.swipes.undo');

    Route::post('/discover/reset', [
        SwipeDiscoveryController::class,
        'reset',
    ])->name('discover.reset');

    Route::post('/discover/assist', [
        SwipeDiscoveryController::class,
        'assist',
    ])->name('discover.assist');

    Route::post('/destinations/{destination:slug}/reviews', [
        ReviewController::class,
        'store',
    ])->name('reviews.store');

    /*
     * Closes the loop this page previously left open: it referenced no
     * itinerary at all, so someone who found a place by browsing had to go and
     * find their trip again to do anything with it.
     *
     * Declared AFTER the reviews route and never before the show route, because
     * this is a three-segment POST and `/destinations/{destination:slug}` is a
     * two-segment GET. Nothing here can be swallowed by route-model binding —
     * the trap `admin/sources/status` fell into is a single extra segment on the
     * same method, which this is not.
     */
    Route::post('/destinations/{destination:slug}/add-to-trip', [
        DestinationController::class,
        'addToTrip',
    ])->name('destinations.add-to-trip');

    Route::resource('itineraries', ItineraryController::class)
        ->only([
            'index',
            'create',
            'store',
            'show',
            'update',
            'destroy',
        ]);

    Route::post('/itineraries/{itinerary}/days', [
        ItineraryController::class,
        'addDay',
    ])->name('itineraries.days.store');

    /*
     * The PDF export. Declared AFTER the resource so `itineraries/{itinerary}`
     * cannot swallow it -- `download` would otherwise resolve as a show request
     * for an itinerary whose slug is "download", the same route-ordering trap
     * `admin/sources/status` and the quickhop form already sit on.
     */
    Route::get('/itineraries/{itinerary}/download', [
        ItineraryController::class,
        'download',
    ])->name('itineraries.download');

    /*
     * WHERE THE TRAVELLER GETS THE SHARE LINK.
     *
     * Authenticated like everything else in this group: the page acts ON
     * someone's itinerary, so ownership runs through ensureOwner() as it does
     * on the PDF. The link it mints is public; the ability to mint one
     * never is.
     */
    Route::get('/itineraries/{itinerary}/share', [
        ItineraryController::class,
        'shareLink',
    ])->name('itineraries.share');

    Route::post('/itineraries/{itinerary}/share', [
        ItineraryController::class,
        'regenerateShareLink',
    ])->name('itineraries.share.regenerate');

    Route::get('/itineraries/{itinerary}/calendar', [
        ItineraryController::class,
        'calendar',
    ])->name('itineraries.calendar');

    Route::patch('/itineraries/{itinerary}/budget', [
        ItineraryController::class,
        'updateBudget',
    ])->name('itineraries.budget.update');

    Route::delete('/itineraries/{itinerary}/days/{day}', [
        ItineraryController::class,
        'removeDay',
    ])->name('itineraries.days.destroy');

    Route::post('/itineraries/{itinerary}/schedule', [
        ItineraryController::class,
        'schedule',
    ])->name('itineraries.schedule');

    Route::patch('/itineraries/{itinerary}/complete', [
        ItineraryController::class,
        'complete',
    ])->name('itineraries.complete');
});

/*
 * THE PUBLIC LINK ITSELF, and it is deliberately OUTSIDE the auth group:
 * the whole point is that a recipient needs no account and no session.
 *
 * The access control is not in this line at all. It is that
 * `Itinerary::sharedPreview()` only accepts a token the table actually
 * carries, so an itinerary nobody issued a share for 404s on a lookup no
 * token can ever satisfy. The token shape is constrained to exactly what
 * Str::random(40) produces
 * — [A-Za-z0-9]{40} — so a malformed token refuses on the constraint rather
 * than reaching the database.
 */
Route::get('/itineraries/{itinerary}/shared/{token}', [
    ItineraryController::class,
    'sharedPreview',
])->name('itineraries.shared')->where('token', '[A-Za-z0-9]{40}');

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminController::class)
            ->name('dashboard');

        // Crawling replaced by LLM discovery; routes disabled.

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

        /*
         * BULK EDIT. Declared BEFORE the resource, so `POST
         * /admin/destinations/bulk` is never read as a resource route — the
         * `admin/sources/status` trap, which is one extra segment on the same
         * method and cost a real bug.
         */
        Route::post('/destinations/bulk', [
            AdminDestinationController::class,
            'bulkUpdate',
        ])->name('destinations.bulk');

        Route::resource(
            'destinations',
            AdminDestinationController::class
        )->except(['show']);

        Route::patch(
            '/places/{place}/archive',
            [
                AdminPlaceController::class,
                'archive',
            ]
        )->name('places.archive');

        Route::patch(
            '/places/{place}/restore',
            [
                AdminPlaceController::class,
                'restore',
            ]
        )->name('places.restore');

        Route::resource(
            'places',
            AdminPlaceController::class
        )->except(['show']);

        Route::get('/reviews', [
            AdminReviewController::class,
            'index',
        ])->name('reviews.index');

        Route::delete('/reviews/{review}', [
            AdminReviewController::class,
            'destroy',
        ])->name('reviews.destroy');

        Route::middleware('super-admin')->group(function () {
            Route::get('/users', [
                AdminUserController::class,
                'index',
            ])->name('users.index');

            Route::patch('/users/{user}/make-admin', [
                AdminUserController::class,
                'makeAdmin',
            ])->name('users.make-admin');

            Route::patch('/users/{user}/demote-admin', [
                AdminUserController::class,
                'demoteAdmin',
            ])->name('users.demote-admin');
        });
    });

require __DIR__.'/auth.php';