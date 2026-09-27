<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_source_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('content_hash', 64);
            $table->string('raw_content_path', 500);
            $table->unsignedSmallInteger('http_status');
            $table->string('content_type')->nullable();
            $table->timestamp('fetched_at');
            $table->string('parser_version')->default('1.0');
            $table->timestamps();

            $table->index(
                ['destination_source_id', 'fetched_at'],
                'dss_source_fetched_idx'
            );

            $table->index(
                'content_hash',
                'dss_content_hash_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_source_snapshots');
    }
};