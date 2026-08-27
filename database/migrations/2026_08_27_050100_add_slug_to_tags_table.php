<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tags', 'name')) {
            Schema::table('tags', function (Blueprint $table) {
                $table->string('name')->nullable()->after('id');
            });
        }

        if (!Schema::hasColumn('tags', 'slug')) {
            Schema::table('tags', function (Blueprint $table) {
                $table->string('slug')->nullable()->unique()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tags', 'slug')) {
            Schema::table('tags', function (Blueprint $table) {
                $table->dropUnique('tags_slug_unique');
                $table->dropColumn('slug');
            });
        }

        if (Schema::hasColumn('tags', 'name')) {
            Schema::table('tags', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
};