<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_access_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('restriction_type')->default('verification_pending');
            $table->string('access_level')->default('restricted');
            $table->json('allowed_actions')->nullable();
            $table->json('blocked_actions')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('can_be_lifted_early')->default(true);
            $table->json('lift_conditions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['organization_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_access_restrictions');
    }
};

