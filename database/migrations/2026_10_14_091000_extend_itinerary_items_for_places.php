<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an itinerary stop be a place, a meal, a hotel, or something the traveller
 * typed in themselves.
 *
 * `itinerary_items.destination_id` alone can only express "an attraction this
 * traveller liked". The panel asked for where they will eat and where they will
 * stay, and neither is an attraction, so the stop needs three more shapes.
 *
 * `item_type` IS THE DISCRIMINATOR AND IS DEFAULTED TO 'attraction', so all 435
 * existing items are correct the moment this runs and nothing has to be backfilled
 * -- the same reason `add_carousel_fields_to_destinations_table` defaulted
 * `is_featured` true. A nullable `item_type` would have meant every existing
 * render had to handle a third "unknown" state that describes nothing real.
 *
 * ONE OF destination_id / place_id IS SET, OR NEITHER FOR A CUSTOM ENTRY. That is
 * not enforced by a CHECK constraint, because MySQL 5.7 and MariaDB ignore
 * CHECK, and because the rule involves a nullable foreign key on a table this
 * project's `repair_itinerary_*` migrations already had to loosen. It is enforced
 * in `ItineraryItem::hasKnownLocation()` and at the request boundary instead,
 * which is the same place the existing ownership boundary lives.
 *
 * `place_id` IS `nullOnDelete`, NOT `cascadeOnDelete`. `destination_id` cascades,
 * and deliberately: a destination is permanent catalogue data and
 * `Admin\DestinationController::destroy` removes itinerary items inside a
 * transaction on purpose. A PLACE is different -- a restaurant closes, a hotel
 * rebrands, and an admin retires rows. Cascading there would silently delete
 * stops out of a traveller's saved trip, which is the exact failure that made
 * `ItineraryEditor::removeDay()` refuse rather than clean up. Nulling the link
 * leaves the stop in place: `item_type` still says it was a meal, and `note`
 * still says what the traveller typed about it.
 *
 * THE ROUTE COLUMNS ARE THE PANEL'S "DISTANCE SHOULD BE ACCOUNTED FOR".
 * `travel_minutes_from_previous` already exists and is a flat
 * `ItinerarySchedule::TRAVEL_MINUTES` (45) for every leg in the app regardless of
 * how far apart two places actually are, which is the estimate being objected to.
 * These four columns are where a real routed answer goes when Day 6-7 adds the
 * provider. `route_status` defaults to 'unresolved' so a leg with no route says
 * so, rather than rendering as zero minutes and reading as "it is right there".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            // attraction | meal | lodging | custom. See ItineraryItem::TYPES.
            if (! Schema::hasColumn('itinerary_items', 'item_type')) {
                $table->string('item_type')->default('attraction')->after('id');
            }

            if (! Schema::hasColumn('itinerary_items', 'place_id')) {
                $table->foreignId('place_id')
                    ->nullable()
                    ->after('destination_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            // The traveller's own booking: a hotel they already reserved, a
            // restaurant a relative recommended. All four nullable, and the
            // latitude/longitude pair deliberately IS, because a place with an
            // address and no pin cannot be routed from and must not be given a
            // made-up one to make the map work.
            if (! Schema::hasColumn('itinerary_items', 'custom_name')) {
                $table->string('custom_name')->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'custom_address')) {
                $table->string('custom_address')->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'custom_latitude')) {
                $table->decimal('custom_latitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'custom_longitude')) {
                $table->decimal('custom_longitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'route_distance_meters')) {
                $table->unsignedInteger('route_distance_meters')->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'route_duration_minutes')) {
                $table->unsignedInteger('route_duration_minutes')->nullable();
            }

            if (! Schema::hasColumn('itinerary_items', 'route_provider')) {
                $table->string('route_provider')->nullable();
            }

            // unresolved | resolved. Never defaulted to resolved, and never
            // inferred from the two numbers above being present -- Day 6-7
            // writes both together or neither.
            if (! Schema::hasColumn('itinerary_items', 'route_status')) {
                $table->string('route_status')->default('unresolved');
            }

            // A stop the traveller pinned. Gemini's revision pass and the editor's
            // replace action both have to leave these alone, which is only
            // expressible if the trip records which stops were pinned.
            if (! Schema::hasColumn('itinerary_items', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
        });
    }

    public function down(): void
    {
        // The foreign key is dropped for EVERY driver, not just MySQL.
        //
        // SQLite has no `ALTER TABLE ... DROP CONSTRAINT`; Laravel answers it by
        // rebuilding the table, and that rebuild FAILS if a column being dropped is
        // still named in a foreign key definition:
        //
        //     error in table itinerary_items after drop column:
        //     unknown column "place_id" in foreign key definition
        //
        // which is a failure on the TEST database and not on production, so a
        // mysql-only guard would have looked correct while breaking every rollback
        // the suite performs. Laravel translates `dropForeign` into the table
        // rebuild it needs on SQLite, so dropping it first is correct on both.
        if (Schema::hasColumn('itinerary_items', 'place_id')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->dropForeign(['place_id']);
            });
        }

        Schema::table('itinerary_items', function (Blueprint $table) {
            $present = array_values(array_filter(
                [
                    'item_type',
                    'place_id',
                    'custom_name',
                    'custom_address',
                    'custom_latitude',
                    'custom_longitude',
                    'route_distance_meters',
                    'route_duration_minutes',
                    'route_provider',
                    'route_status',
                    'is_locked',
                ],
                fn (string $column): bool => Schema::hasColumn('itinerary_items', $column)
            ));

            if ($present !== []) {
                $table->dropColumn($present);
            }
        });
    }
};