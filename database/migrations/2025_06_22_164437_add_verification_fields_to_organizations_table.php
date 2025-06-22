<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('country');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
            $table->json('verification_data')->nullable()->after('verified_at');
            $table->string('verification_status')->default('pending')->after('verification_data');
            $table->text('verification_notes')->nullable()->after('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'is_verified',
                'verified_at', 
                'verification_data',
                'verification_status',
                'verification_notes'
            ]);
        });
    }
};
