<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns for the shared destinations carousel.
 *
 * The carousel is a VIEW over `destinations` -- there is no carousel table, and
 * that is deliberate: a separate `carousel_items` table is how the fan on the
 * index and the fan on a destination page drift apart, because there is then no
 * single order both can read.
 *
 * `sort_order` exists because there was no stable order to read. `latest()` sorts
 * on `created_at`, and a bulk import writes many rows with the same timestamp, so
 * the order was effectively arbitrary and could differ between two requests in
 * the same page load. Defaulting to 0 means every existing row ties and the
 * secondary sort by name settles it deterministically, so this needs no backfill.
 *
 * `is_featured` is the curation switch. It DEFAULTS TRUE so all 65 active
 * destinations participate today; setting it false later drops a destination out
 * of both fans without touching anything else. Defaulting it false instead would
 * have silently emptied both carousels until someone curated 65 rows by hand.
 *
 * DELIBERATELY NOT ADDED, and the reason matters:
 *
 *   - `published_at`. `is_active` already is the publish switch, and it is what
 *     recommendations, the discover deck, the listing and `show`'s own
 *     `abort_unless(..., 404)` all read. A second flag means two sources of truth
 *     for "is this visible", and they will disagree.
 *   - soft deletes. This project archives with `is_active = false`; there are no
 *     soft deletes anywhere, and `Admin\DestinationController::destroy` removes
 *     reviews, swipes, itinerary items and tag pivots by hand inside a
 *     transaction. Adding `deleted_at` would put a second lifecycle in front of
 *     all of that.
 *   - `country`. All 65 rows are in Luzon. A country column here would hold the
 *     same constant 65 times; `province` and `municipality` are already more
 *     specific and already exist.
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