<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reviews', 'user_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('reviews', 'destination_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->foreignId('destination_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('reviews', 'rating')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->unsignedTinyInteger('rating')->default(5);
            });
        }

        if (!Schema::hasColumn('reviews', 'comment')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->text('comment')->nullable();
            });
        }

        if (!Schema::hasColumn('reviews', 'status')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->string('status')->default('published');
            });
        }
    }

    public function down(): void
    {
        //
    }
};