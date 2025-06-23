<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_module_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_module_id')->constrained()->onDelete('cascade');
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'suspended', 'pending'])->default('active');
            $table->json('settings')->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
            
            $table->unique(['organization_id', 'organization_module_id'], 'org_module_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_module_activations');
    }
}; 