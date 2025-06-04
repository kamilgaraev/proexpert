<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_subscription_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_addon_id')->constrained()->onDelete('restrict');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_subscription_addons');
    }
}; 