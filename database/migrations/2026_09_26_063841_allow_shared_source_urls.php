<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->dropUnique(
                'destination_sources_source_url_unique'
            );

            $table->unique(
                ['destination_id', 'source_url'],
                'destination_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->dropUnique(
                'destination_source_unique'
            );

            $table->unique(
                'source_url',
                'destination_sources_source_url_unique'
            );
        });
    }
};