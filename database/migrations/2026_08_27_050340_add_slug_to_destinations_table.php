<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('destinations', 'name')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('name')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'slug')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('slug')->nullable()->unique();
            });
        }

        if (!Schema::hasColumn('destinations', 'description')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->text('description')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'province')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('province')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'municipality')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('municipality')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'latitude')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'longitude')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'budget_level')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('budget_level')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'entrance_fee')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->decimal('entrance_fee', 10, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('destinations', 'estimated_cost')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->decimal('estimated_cost', 10, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('destinations', 'recommended_minutes')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->unsignedSmallInteger('recommended_minutes')->default(120);
            });
        }

        if (!Schema::hasColumn('destinations', 'opening_time')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->time('opening_time')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'closing_time')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->time('closing_time')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'image_url')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('image_url')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'is_active')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        $columns = [
            'slug',
            'description',
            'province',
            'municipality',
            'latitude',
            'longitude',
            'budget_level',
            'entrance_fee',
            'estimated_cost',
            'recommended_minutes',
            'opening_time',
            'closing_time',
            'image_url',
            'is_active',
            'name',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('destinations', $column)) {
                Schema::table('destinations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};