<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public read-only share link.
 *
 * The COLUMN is the whole access control on the public route: a token the
 * table WAS NOT ISSUED matches nothing, so an unissued share cannot leak.
 * Un-guessable at ~5.95 bits per character (Str::random's [A-Za-z0-9] pool),
 * and the column is not writeable through any user-facing endpoint — it is
 * minted only inside `Itinerary::shareToken()`, lazily, on first share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->string('share_token', 40)
                ->nullable()
                ->unique()
                ->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropUnique('itineraries_share_token_unique');
            $table->dropColumn('share_token');
        });
    }
};
