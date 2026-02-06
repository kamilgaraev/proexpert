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
        Schema::create('purchase_request_number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->integer('year');
            $table->integer('month');
            $table->integer('last_number')->default(0);
            $table->timestamps();
            
            // Уникальный индекс на комбинацию организация + год + месяц
            $table->unique(['organization_id', 'year', 'month']);
            
            // Индекс для быстрого поиска
            $table->index(['organization_id', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_request_number_counters');
    }
};
