<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contract_payments');
    }

    public function down(): void
    {
        if (Schema::hasTable('contract_payments')) {
            return;
        }

        Schema::create('contract_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->date('payment_date')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('payment_type');
            $table->string('reference_document_number')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('payment_date');
            $table->index('payment_type');
        });
    }
};
