<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns for the shared destinations stage.
 *
 * The stage is a VIEW over `destinations` -- there is no `carousel_items` table,
 * and that is deliberate. A separate table is how the fan on `/destinations` and
 * the fan on `/destinations/{slug}` drift apart, because there is then no single
 * order both can read.
 *
 * `sort_order` exists because there was no stable order to read. `latest()` sorts
 * on `created_at`, and a bulk CSV import writes many rows carrying the same
 * timestamp, so the order was effectively arbitrary and two requests could
 * disagree. Defaulting to 0 makes every existing row tie, and the secondary sort
 * by name settles it deterministically, so this needs no backfill.
 *
 * `is_featured` is the curation switch, and it DEFAULTS TRUE so all 65 active
 * destinations are in the stage today. Defaulting it false would have emptied the
 * stage until someone curated 65 rows by hand.
 *
 * DELIBERATELY NOT ADDED, and the reason matters:
 *
 *   - `published_at`. `is_active` already is the publish switch, and it is what
 *     the listing, recommendations, the discover deck and `show`'s own
 *     `abort_unless(..., 404)` all read. A second flag means two sources of truth
 *     for "is this visible", and they will disagree.
 *   - soft deletes. This project archives with `is_active = false`; there are no
 *     soft deletes on `destinations` anywhere, and
 *     `Admin\DestinationController::destroy` removes reviews, swipes, itinerary
 *     items and tag pivots by hand inside a transaction. Adding `deleted_at` would
 *     put a second lifecycle in front of all of that.
 *   - `country`. All 65 rows are in Luzon. It would hold the same constant 65
 *     times, and `province` and `municipality` are already more specific and
 *     already exist.
 *   - a slide/carousel table. The stage's slides are photographs, and photographs
 *     are already `destinations.image_url` plus `destination_images` rows. A third
 *     place to store them is a third thing to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('id');
            $table->boolean('is_featured')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_featured']);
        });
    }
};
