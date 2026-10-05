<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra photos for a destination's page, as a carousel.
 *
 * The thumbnail is deliberately NOT here. It stays on `destinations.image_url`,
 * which is what all six views that render a destination already read, and which
 * the CSV seeder populates for all 65 rows. Moving it would mean a data migration
 * and six view changes for no gain; leaving it means the catalogue's existing
 * imagery keeps working untouched and this table is purely additive.
 *
 * `sort_order` is not unique-constrained. Reordering two images would collide and
 * the cap of three is enforced in the controller, where the files are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            // Reading a destination's carousel is the only query this table ever
            // serves, and it is always ordered.
            $table->index(['destination_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_images');
    }
};
