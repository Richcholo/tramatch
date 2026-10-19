<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE TRAVELLER'S OWN PESO CEILING FOR A TRIP.
 *
 * There was no way to say "this trip has to fit ₱15,000" anywhere in the
 * app, and the obvious shortcut was refused: `budget_level` is a catalogue
 * FILTER (an exact-match tier, ADR 0002), not an amount, so inventing a peso
 * figure per tier would be fabricating a number nobody published. The
 * ceiling is therefore something the traveller states, and it is nullable —
 * "no budget set" is a real state and not a zero.
 *
 * Nullable decimal(10,2) to match `total_estimated_cost`, which this is
 * compared against; there is no currency column because the app prices in
 * PHP only, and a single-column currency would imply a multi-currency app
 * that does not exist.
 *
 * Guarded because this migration may arrive at a server that already has the
 * column (the "column already exists" deploy failure is recorded in AGENTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('itineraries', 'budget_amount')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->decimal('budget_amount', 10, 2)->nullable()->after('budget_level');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('itineraries', 'budget_amount')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->dropColumn('budget_amount');
            });
        }
    }
};
