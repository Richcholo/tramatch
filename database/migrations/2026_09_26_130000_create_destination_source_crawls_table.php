<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_source_crawls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('outcome', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('content_changed')->nullable();
            $table->json('extracted_fields')->nullable();
            $table->unsignedSmallInteger('proposals_created')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(
                ['destination_source_id', 'started_at'],
                'dsc_source_started_idx'
            );

            $table->index('outcome', 'dsc_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_source_crawls');
    }
};
