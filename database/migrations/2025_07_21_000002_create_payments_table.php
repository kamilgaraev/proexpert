<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Payment; // Для использования констант статусов

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_subscription_id')->nullable()->constrained()->onDelete('set null'); // Платеж может быть не связан с подпиской, или подписка удалена
            
            $table->string('payment_gateway_payment_id')->unique(); // Уникальный ID платежа в шлюзе (например, от YooKassa)
            $table->string('payment_gateway_charge_id')->nullable()->index(); // ID конкретной операции/списания, если отличается от ID платежа
            
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->default(Payment::STATUS_PENDING);
            $table->text('description')->nullable();
            $table->timestamp('paid_at')->nullable(); // Фактическое время успешной оплаты
            
            $table->json('payment_method_details')->nullable(); // Детали использованного метода оплаты (тип карты, и т.д.)
            $table->json('gateway_response')->nullable(); // Для хранения полного ответа от шлюза
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
}; 