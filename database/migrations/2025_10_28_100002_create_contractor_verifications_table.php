<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->onDelete('cascade');
            $table->foreignId('registered_organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            $table->foreignId('customer_organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('status')->default('pending_customer_confirmation');
            $table->string('verification_token')->unique();
            $table->integer('verification_score')->default(0);
            $table->json('verification_data')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            
            $table->index(['contractor_id', 'status']);
            $table->index('verification_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_verifications');
    }
};

