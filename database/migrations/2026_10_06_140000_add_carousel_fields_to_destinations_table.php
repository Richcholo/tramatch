<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns for the destinations stage.
 *
 * The stage is a VIEW over `destinations` -- there is no `stage_items` table, and
 * that is deliberate. A separate table is how the order gets a second source of
 * truth, and the stage's caption then names one destination over another's
 * photograph.
 *
 * `sort_order` exists because there was no stable order to read. `latest()` sorts
 * on `created_at`, and a bulk CSV import writes many rows carrying the same
 * timestamp, so the order was effectively arbitrary and two requests in the same
 * page load could disagree. Defaulting to 0 makes every existing row tie, and the
 * secondary sort by name in the presenter settles it deterministically, so this
 * needs no backfill.
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
 *   - a slide/stage table. The stage's slides are photographs, and photographs
 *     are already `destinations.image_url` plus `destination_images` rows. A third
 *     place to store them is a third thing to keep in step.
 */
return new class extends Migration
{
    /**
     * EACH COLUMN IS GUARDED, and that is not defensive style -- it is the point
     * of this migration.
     *
     * These two columns were added, and then the migration was DELETED, when the
     * first fan of this stage was built and reverted. The production server had
     * already run the deleted
     * `2026_10_06_120000_add_carousel_fields_to_destinations_table`, so it has
     * BOTH COLUMNS PHYSICALLY PRESENT plus a `migrations` row for a file no
     * longer on disk.
     *
     * This file has a different name, so Laravel sees an unrecorded migration and
     * tries to add columns that are already there:
     *
     *     SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'sort_order'
     *
     * and `migrate` aborts, leaving the server unable to deploy code that only
     * ever needed two defaulted columns. That is the worst possible outcome for
     * this migration, and it is why the guards exist.
     *
     * RENAMING THIS FILE to `..._120000_...` would also fix that server, and it
     * touches no data -- but it silently depends on which name a given server
     * happened to record. Any server that did NOT run the old migration would then
     * treat this one as already applied and skip it, leaving `is_featured`
     * missing from a fresh install. `hasColumn()` is correct in every case: a
     * fresh database gets both columns, and a server that already has them moves
     * on and records this migration properly.
     *
     * `test_the_stage_migration_is_safe_where_the_columns_already_exist` is the
     * production scenario as a test.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('destinations', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('id');
            }

            if (! Schema::hasColumn('destinations', 'is_featured')) {
                $table->boolean('is_featured')->default(true)->after('is_active');
            }
        });
    }

    /**
     * Guarded for the same reason: it is the inverse of `up()`, and an unguarded
     * inverse of a guarded step fails on exactly the environments where the
     * guard was what mattered.
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $present = array_values(array_filter(
                ['sort_order', 'is_featured'],
                fn (string $column): bool => Schema::hasColumn('destinations', $column)
            ));

            if ($present !== []) {
                $table->dropColumn($present);
            }
        });
    }
};
