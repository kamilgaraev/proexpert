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
        Schema::create('contract_performance_acts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->string('act_document_number')->nullable();
            $table->date('act_date');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->date('approval_date')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('act_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_performance_acts');
    }
}; 