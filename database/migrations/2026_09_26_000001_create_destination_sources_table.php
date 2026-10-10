<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_url', 1000);
            $table->string('source_type')->default('official');
            $table->boolean('allowed_by_policy')->default(false);
            $table->unsignedSmallInteger('robots_status')->nullable();
            $table->text('robots_content')->nullable();
            $table->timestamp('robots_checked_at')->nullable();
            $table->unsignedSmallInteger('crawl_delay_seconds')->default(10);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['destination_id', 'status']);
            $table->unique('source_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_sources');
    }
};
