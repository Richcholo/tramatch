<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'local_transportation' => function (Blueprint $table) {
                $table->text('local_transportation')->nullable();
            },
            'entrance_fee_display' => function (Blueprint $table) {
                $table->string('entrance_fee_display')->nullable();
            },
            'typical_food_drinks' => function (Blueprint $table) {
                $table->text('typical_food_drinks')->nullable();
            },
            'activities' => function (Blueprint $table) {
                $table->text('activities')->nullable();
            },
            'estimated_cost_display' => function (Blueprint $table) {
                $table->string('estimated_cost_display')->nullable();
            },
            'pricing_type' => function (Blueprint $table) {
                $table->string('pricing_type')->nullable();
            },
            'fee_operational_notes' => function (Blueprint $table) {
                $table->text('fee_operational_notes')->nullable();
            },
            'recommended_minutes_display' => function (Blueprint $table) {
                $table->string('recommended_minutes_display')->nullable();
            },
            'possible_expenses' => function (Blueprint $table) {
                $table->text('possible_expenses')->nullable();
            },
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('destinations', $column)) {
                Schema::table('destinations', $definition);
            }
        }
    }

    public function down(): void
    {
        $columns = [
            'local_transportation',
            'entrance_fee_display',
            'typical_food_drinks',
            'activities',
            'estimated_cost_display',
            'pricing_type',
            'fee_operational_notes',
            'recommended_minutes_display',
            'possible_expenses',
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
