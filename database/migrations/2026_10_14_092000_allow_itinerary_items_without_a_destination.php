<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an itinerary stop exist without a `destinations` row.
 *
 * A MEAL, A HOTEL AND A TRAVELLER'S OWN BOOKING ALL HAVE NO DESTINATION. This
 * is the column that says so, and it is why this migration exists rather than
 * being folded into the one that added `item_type`.
 *
 * WHY NO EXISTING MIGRATION IS FIXED INSTEAD. The bug is this:
 *
 *   2026_08_27_034206_create_itinerary_items_table
 *       $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
 *   2026_09_05_065400_repair_itinerary_tables
 *       if (! Schema::hasColumn('itinerary_items', 'destination_id')) {
 *           $table->foreignId('destination_id')->nullable()->constrained()...
 *       }
 *
 * The repair migration LOOKS like it made the column nullable, and it would have,
 * on a database that never ran the create step. But a fresh install runs BOTH, in
 * order: the create migration makes `destination_id` NOT NULL, and the repair's
 * `hasColumn()` guard then sees the column and skips it. So the repair has never
 * done anything on any real database, and `destination_id` is NOT NULL everywhere
 * -- exactly the opposite of what the file reads like.
 *
 * That is why the guard was not "fixed": editing the repair would not run on any
 * server that already has the column, so no installation would ever change.
 *
 * WHY THIS SURFACED ONLY NOW. `ItineraryEditor::writeDay()` already wrote
 * `'destination_id' => $destination?->id` and guarded its insert with
 * `elseif ($destination)`, so the CODE has always allowed a null. Nothing in the
 * app ever created one, so nothing ever hit the constraint. Adding `item_type`
 * created the first request that can.
 *
 * `change()` IS SAFE HERE EVEN THOUGH THE COLUMN CARRIES A FOREIGN KEY: it
 * alters nullability only and leaves the constraint in place, which is what
 * `Admin\DestinationController::destroy()` still relies on to clear itinerary
 * items when a destination is permanently removed.
 */
return new class extends Migration
{
    /**
     * Guarded on NULLABILITY, not on existence -- and this is the whole point.
     *
     * `hasColumn()` would return true on every database and the migration would do
     * nothing at all, which is precisely the mistake the repair migration above
     * made. So the guard asks the actual question: is this column currently
     * nullable? A server that somehow already has it nullable moves on and records
     * the migration, which is what `deploy.sh` expects.
     */
    public function up(): void
    {
        if ($this->destinationIdIsNullable()) {
            return;
        }

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->foreignId('destination_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * Whether `itinerary_items.destination_id` currently allows null.
     *
     * `Schema::getColumns()` exists in Laravel 11+ and reports `nullable` per
     * column on MySQL, SQLite and SQL Server alike, which is what makes this guard
     * portable. The older alternative, reading `information_schema` directly,
     * answers for MySQL only and would have thrown on the SQLite test database --
     * and a migration that only works on the production driver is exactly the
     * kind that ships untested.
     */
    private function destinationIdIsNullable(): bool
    {
        if (! Schema::hasColumn('itinerary_items', 'destination_id')) {
            // The table does not exist yet, so there is nothing to relax. Running
            // `alter` here would fail on a database mid-migration-chain.
            return true;
        }

        foreach (Schema::getColumns('itinerary_items') as $column) {
            if (($column['name'] ?? null) === 'destination_id') {
                return (bool) ($column['nullable'] ?? false);
            }
        }

        return true;
    }

    /**
     * Restores the NOT NULL constraint.
     *
     * Only ever safe when no meal, hotel or custom stop exists, because those rows
     * hold null here by design. A rollback that would strand them fails loudly at
     * the database instead of silently deleting or inventing a destination -- which
     * is the right way round for a column this load-bearing.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('itinerary_items', 'destination_id')) {
            return;
        }

        if (! $this->destinationIdIsNullable()) {
            return;
        }

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->foreignId('destination_id')
                ->nullable(false)
                ->change();
        });
    }
};