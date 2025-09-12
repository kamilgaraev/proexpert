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
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            
            // Статус
            $table->enum('status', ['active', 'suspended', 'expired', 'trial', 'pending'])->default('active');
            
            // Временные рамки
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            
            // Биллинг
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('payment_details')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            
            // Настройки и статистика
            $table->json('module_settings')->nullable();
            $table->json('usage_stats')->nullable();
            
            // Отмена
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->timestamps();
            
            // Ограничения и индексы
            $table->unique(['organization_id', 'module_id'], 'org_module_unique');
            $table->index(['organization_id', 'status']);
            $table->index('expires_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_module_activations');
    }
};
