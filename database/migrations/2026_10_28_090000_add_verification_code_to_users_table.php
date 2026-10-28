<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The email OTP: a hashed 6-digit code and its expiry, one row per user
     * on the users table itself. Guarded on hasColumn — the deployment the
     * columns may already exist on moves on and records the migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'verification_code_hash')) {
                $table->string('verification_code_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('users', 'verification_code_expires_at')) {
                $table->timestamp('verification_code_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_code_hash', 'verification_code_expires_at']);
        });
    }
};
