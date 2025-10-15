<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_supplementary_agreements_advance_changes');
        
        Schema::dropIfExists('contract_advance_payments');
    }

    public function down(): void
    {
        Schema::create('contract_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('payment_date')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'payment_date']);
            $table->index('payment_date');
        });

        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_advance_changes ON supplementary_agreements USING GIN (advance_changes)');
    }
};

