<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->string('details_url', 1000)->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->dropColumn('details_url');
        });
    }
};
