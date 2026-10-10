<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->string('fetchability')->nullable()->after('crawl_delay_seconds');
            $table->timestamp('fetchability_checked_at')->nullable()->after('fetchability');
            $table->text('fetchability_note')->nullable()->after('fetchability_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('destination_sources', function (Blueprint $table) {
            $table->dropColumn(['fetchability', 'fetchability_checked_at', 'fetchability_note']);
        });
    }
};
