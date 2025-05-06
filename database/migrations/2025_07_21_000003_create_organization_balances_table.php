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
        Schema::create('organization_balances', function (Blueprint $table) {
            $table->id();
            // Убедитесь, что таблица organizations существует
            $table->foreignId('organization_id')->unique()->constrained()->onDelete('cascade');
            $table->bigInteger('balance')->default(0); // Баланс в минорных единицах (копейках)
            $table->string('currency', 3)->default('RUB');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_balances');
    }
}; 