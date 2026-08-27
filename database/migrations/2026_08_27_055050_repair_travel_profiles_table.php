<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('travel_profiles', 'user_id')) {
            Schema::table('travel_profiles', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('travel_profiles', 'budget_level')) {
            Schema::table('travel_profiles', function (Blueprint $table) {
                $table->string('budget_level')->default('economy');
            });
        }

        if (!Schema::hasColumn('travel_profiles', 'group_size')) {
            Schema::table('travel_profiles', function (Blueprint $table) {
                $table->unsignedSmallInteger('group_size')->default(1);
            });
        }

        if (!Schema::hasColumn('travel_profiles', 'trip_duration_days')) {
            Schema::table('travel_profiles', function (Blueprint $table) {
                $table->unsignedSmallInteger('trip_duration_days')->default(1);
            });
        }

        if (!Schema::hasColumn('travel_profiles', 'preferred_region')) {
            Schema::table('travel_profiles', function (Blueprint $table) {
                $table->string('preferred_region')->nullable();
            });
        }
    }

    public function down(): void
    {
        //
    }
};