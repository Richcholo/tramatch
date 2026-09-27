<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destination_source_crawls', function (Blueprint $table) {
            $table->string('requested_url', 1000)->nullable()->after('http_status');
            $table->string('final_url', 1000)->nullable()->after('requested_url');
            $table->string('robots_state', 20)->nullable()->after('final_url');
        });
    }

    public function down(): void
    {
        Schema::table('destination_source_crawls', function (Blueprint $table) {
            $table->dropColumn(['requested_url', 'final_url', 'robots_state']);
        });
    }
};
