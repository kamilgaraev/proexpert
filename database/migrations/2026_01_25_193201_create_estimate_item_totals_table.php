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
        Schema::create('estimate_item_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_item_id')->constrained()->onDelete('cascade');
            
            $table->string('data_type')->nullable(); // OT, EM, MAT, TotalPos, TotalWithNP, Nacl, Plan, FOT и т.д.
            $table->string('caption')->nullable();
            $table->decimal('quantity_for_one', 15, 4)->nullable();
            $table->decimal('quantity_total', 15, 4)->nullable();
            $table->decimal('for_one_curr', 15, 2)->nullable();
            $table->decimal('total_curr', 15, 2)->nullable();
            $table->decimal('total_base', 15, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['estimate_item_id', 'data_type']);
            $table->index(['estimate_item_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_item_totals');
    }
};
