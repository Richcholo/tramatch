<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE NUDGE, PERSISTED.
 *
 * Until now the discovery assist was entirely stateless: the model's picks
 * were flashed to the session, rendered once, and gone — the model's output
 * could not become anything the system acted on. This table is where it
 * becomes state: one batch of validated picks per traveller, in the model's
 * own rank order, which `SwipeDeckService::cards()` promotes to the front of
 * the next real deal.
 *
 * Only VALIDATED picks are ever written (see `DiscoveryAssist::remember()`),
 * so an id the model invented cannot reach this table either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_suggestions', function (Blueprint $table) {
            $table->id();

            // Cascade both: a deleted traveller leaves no orphans, and a
            // permanently deleted destination leaves no suggestion pointing
            // at a row that no longer exists (the admin destroy path).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();

            // The model's own ranking — the one decision it owns — kept as a
            // column so promotion can replay it instead of re-deriving an
            // order the model was never asked about.
            $table->unsignedTinyInteger('rank');

            // decodePicks() refuses anything longer than 280 before a pick is
            // ever shown, so the column can never be the layer that truncates.
            $table->string('why', 280);

            $table->timestamps();

            // ONE BATCH PER TRAVELLER PER DESTINATION: a fresh nudge replaces
            // the previous batch wholesale (see remember()), and the model was
            // told for distinct ids, so a duplicate here would be a bug
            // rather than a second opinion.
            $table->unique(['user_id', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_suggestions');
    }
};
