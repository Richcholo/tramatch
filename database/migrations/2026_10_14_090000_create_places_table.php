<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `places`: the food and lodging a traveller needs, which `destinations` has no
 * room for.
 *
 * WHY A NEW TABLE RATHER THAN MORE `destinations` rows. `Destination` is a
 * curated attraction catalogue with a fixed vocabulary: `budget_level` is an
 * EXACT match against the traveller's profile, `recommended_minutes` is a visit
 * length, and the whole `hours_kind` list is written for gates and
 * registration desks. A hotel has a room rate and a check-in time, not a visit
 * duration, and a restaurant has a price band and meal slots. Filing them as
 * destinations would make the recommendation score, the swipe deck and the PDF's
 * "EST. TOTAL" describe a hotel as an attraction.
 *
 * It also keeps the crawler's existing boundary intact. The crawler may propose
 * fees and hours and nothing else; new place types need a new extraction and a
 * new approval queue, which is Day 2-3's work, not this migration's.
 *
 * THE THREE DECISIONS IN HERE THAT MATTER MOST:
 *
 * 1. `price_min` AND `price_max` ARE NULLABLE. On `destinations`,
 *    `estimated_cost` is `decimal default 0`, so "this costs nothing" and "nobody
 *    has verified what this costs" are the same stored value. A trip total built
 *    by summing that column reports a hotel as free. A missing price here is
 *    NULL and `Place::hasVerifiedPrice()` is false, so the itinerary can say the
 *    budget is incomplete instead of asserting a number that is not true.
 *
 * 2. `province` REUSES THE `destinations.province` VOCABULARY, deliberately.
 *    `ItineraryGenerator` selects `where('province', $area)` and `itineraries.area`
 *    holds a province string, so a food place filed under "Benguet" is reachable by
 *    the same filter that finds the attractions beside it. A separate region
 *    vocabulary would need a mapping table and a second source of truth for "what
 *    area is this trip in".
 *
 * 3. `budget_level` EXISTS AND IS AN EXACT MATCH, like the destination's. This is
 *    a trap, not a convenience: `RecommendationService`, `SwipeDeckService`,
 *    `ItineraryGenerator` and `SwipeDiscoveryController` all filter on equality,
 *    so a place filed under the wrong tier is INVISIBLE to every traveller in the
 *    other two, rather than merely ranking lower. Count coverage per tier before
 *    claiming a cluster is complete. See `clusterCoverage()`.
 *
 * The hours columns are a deliberate copy of `destinations`, because
 * `HasOpeningHours` reads them by name and is now shared by both models. That
 * column set is the contract; adding a place-specific hour column instead would
 * mean the trait only works for one of its two users.
 *
 * Guarded per-column, like `add_carousel_fields_to_destinations_table`. A
 * migration deleted from disk is not undone on a server, and an unguarded
 * re-add dies with `SQLSTATE[42S21]: Column already exists`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('places')) {
            return;
        }

        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // 'food' or 'lodging'. See Place::TYPES.
            $table->string('type');

            // Which pilot cluster this place was curated for, or null. A label
            // for curation and for the demo, NOT a filter: nothing in the app
            // requires it, and a place with no cluster is still perfectly usable.
            $table->string('cluster')->nullable();

            $table->string('province');
            $table->string('municipality')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Economy / mid-range / premium. EXACT MATCH, same as destinations.
            $table->string('budget_level');

            // Food only. Both nullable on purpose.
            $table->string('cuisine')->nullable();

            // Which meals this place suits, for the generator to slot a lunch
            // rather than hand a traveller a 22:00 dinner stop. See
            // Place::MEAL_SLOTS. Ignored for lodging.
            $table->json('meal_slots')->nullable();

            // NULLABLE. Null means unknown and must never be read as zero. See
            // the note on the class docblock.
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();

            // What the band above is a price OF. See Place::PRICE_BASES.
            $table->string('price_basis')->nullable();

            // How long to hold this stop: a lunch is 60, a hotel check-in is not
            // a visit at all and this stays 0 for lodging.
            $table->unsignedSmallInteger('recommended_minutes')->default(0);

            // Read by HasOpeningHours. Kept identical to destinations.
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->string('closed_days')->nullable();
            $table->json('daily_hours')->nullable();
            $table->string('hours_kind')->nullable();
            $table->string('operating_status')->nullable();
            $table->text('hours_note')->nullable();

            // Every published place carries a source. A restaurant with no
            // traceable page behind it does not get an entry, because nothing
            // else here can vouch for the hours or the price band.
            $table->string('source_url');
            $table->string('source_label')->nullable();

            // Which of the two verifications a change belongs to, mirroring
            // Destination::hoursFingerprint() / hoursChanged(). Not yet wired to
            // an automatic restamp -- Day 2-3's seeder does that.
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('price_verified_at')->nullable();

            // Archive switch. This project has no soft deletes anywhere:
            // `is_active = false` is how a place is retired, and it is what every
            // public listing reads.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // The planner's hot query: active places of one type in one area.
            // province is the selective prefix, so the type and is_active columns
            // ride along in the index rather than filtering after the fact.
            $table->index(['province', 'type', 'is_active']);
        });
    }

    /**
     * Guarded for the same reason as `up()`: an unguarded inverse of a guarded
     * step fails on exactly the environments where the guard mattered. On a
     * database where `places` never existed -- the far likelier case, since this
     * migration has never been deleted -- there is nothing to drop and throwing
     * here would take a rollback down.
     */
    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};