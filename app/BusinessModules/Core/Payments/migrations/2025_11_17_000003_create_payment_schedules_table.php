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
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            
            $table->integer('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->onDelete('set null');
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['invoice_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};

