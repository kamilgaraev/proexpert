<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\UserSubscription;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained()->onDelete('restrict'); // Не удаляем план, если есть подписчики

            $table->string('status')->default(UserSubscription::STATUS_PENDING_PAYMENT); // Статус подписки
            
            $table->timestamp('trial_ends_at')->nullable(); // Дата окончания пробного периода
            $table->timestamp('starts_at')->nullable(); // Дата начала действия подписки (после оплаты или триала)
            $table->timestamp('ends_at')->nullable(); // Дата окончания подписки (для неавтопродляемых или после отмены)
            $table->timestamp('next_billing_at')->nullable(); // Дата следующего списания для автопродляемых подписок
            $table->timestamp('canceled_at')->nullable(); // Дата отмены пользователем
            $table->timestamp('payment_failure_notified_at')->nullable(); // Когда уведомили о проблеме с оплатой

            // Идентификаторы в платежной системе (для будущей интеграции)
            $table->string('payment_gateway_subscription_id')->nullable()->unique();
            $table->string('payment_gateway_customer_id')->nullable()->index();
            
            $table->timestamps();

            // Уникальный ключ на пользователя и план, если предполагается только одна активная подписка на план
            // $table->unique(['user_id', 'subscription_plan_id'], 'user_plan_unique'); 
            // Однако, лучше разрешить несколько записей, но управлять активной через статус и даты
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
}; 