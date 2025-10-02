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
        Schema::dropIfExists('organization_subscription_addons');
        Schema::dropIfExists('subscription_addons');
    }

    public function down(): void
    {
        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

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
};
