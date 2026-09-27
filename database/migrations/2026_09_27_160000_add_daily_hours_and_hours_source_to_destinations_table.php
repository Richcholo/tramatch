<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->json('daily_hours')->nullable()->after('closed_days');
            $table->string('hours_source_url')->nullable()->after('daily_hours');
            $table->string('hours_source_label')->nullable()->after('hours_source_url');
            $table->text('hours_note')->nullable()->after('hours_source_label');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn([
                'daily_hours',
                'hours_source_url',
                'hours_source_label',
                'hours_note',
            ]);
        });
    }
};
