<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\BalanceTransaction; // Для констант

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_balance_id')->constrained()->onDelete('cascade');
            $table->foreignId('payment_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_subscription_id')->nullable()->constrained()->onDelete('set null');
            
            $table->enum('type', [BalanceTransaction::TYPE_CREDIT, BalanceTransaction::TYPE_DEBIT]);
            $table->bigInteger('amount'); // Сумма транзакции в минорных единицах
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->string('description')->nullable();
            $table->json('meta')->nullable(); // Для хранения доп. деталей, например, ID операции в шлюзе, если это пополнение
            
            $table->timestamps();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
    }
}; 