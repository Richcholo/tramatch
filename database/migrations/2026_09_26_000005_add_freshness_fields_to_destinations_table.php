<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('destinations', 'operating_status')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->string('operating_status')->default('unknown');
            });
        }

        if (!Schema::hasColumn('destinations', 'last_verified_at')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->timestamp('last_verified_at')->nullable();
            });
        }

        if (!Schema::hasColumn('destinations', 'price_verified_at')) {
            Schema::table('destinations', function (Blueprint $table) {
                $table->timestamp('price_verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'operating_status',
            'last_verified_at',
            'price_verified_at',
        ] as $column) {
            if (Schema::hasColumn('destinations', $column)) {
                Schema::table('destinations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
