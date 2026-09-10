<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('itineraries', 'user_id')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('itineraries', 'title')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->string('title')->nullable();
            });
        }

        if (!Schema::hasColumn('itineraries', 'start_date')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->date('start_date')->nullable();
            });
        }

        if (!Schema::hasColumn('itineraries', 'budget_level')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->string('budget_level')->nullable();
            });
        }

        if (!Schema::hasColumn('itineraries', 'trip_duration_days')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->unsignedSmallInteger('trip_duration_days')->default(1);
            });
        }

        if (!Schema::hasColumn('itineraries', 'total_estimated_cost')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->decimal('total_estimated_cost', 10, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('itineraries', 'match_score')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->decimal('match_score', 5, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('itineraries', 'is_completed')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->boolean('is_completed')->default(false);
            });
        }

        if (!Schema::hasColumn('itinerary_days', 'itinerary_id')) {
            Schema::table('itinerary_days', function (Blueprint $table) {
                $table->foreignId('itinerary_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('itinerary_days', 'day_number')) {
            Schema::table('itinerary_days', function (Blueprint $table) {
                $table->unsignedSmallInteger('day_number')->default(1);
            });
        }

        if (!Schema::hasColumn('itinerary_days', 'date')) {
            Schema::table('itinerary_days', function (Blueprint $table) {
                $table->date('date')->nullable();
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'itinerary_day_id')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->foreignId('itinerary_day_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'destination_id')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->foreignId('destination_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'sort_order')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->unsignedSmallInteger('sort_order')->default(1);
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'start_time')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->time('start_time')->nullable();
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'end_time')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->time('end_time')->nullable();
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'travel_minutes_from_previous')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->unsignedInteger('travel_minutes_from_previous')->default(0);
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'estimated_cost')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->decimal('estimated_cost', 10, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('itinerary_items', 'note')) {
            Schema::table('itinerary_items', function (Blueprint $table) {
                $table->text('note')->nullable();
            });
        }
    }

    public function down(): void
    {
        //
    }
};