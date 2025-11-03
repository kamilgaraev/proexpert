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
        Schema::create('estimate_number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->integer('year');
            $table->integer('last_number')->default(0);
            $table->timestamps();
            
            // Уникальный индекс на комбинацию организация + год
            $table->unique(['organization_id', 'year']);
            
            // Индекс для быстрого поиска
            $table->index(['organization_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_number_counters');
    }
};

