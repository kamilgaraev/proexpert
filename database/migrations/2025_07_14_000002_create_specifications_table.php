<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specifications', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // Номер спецификации
            $table->date('spec_date'); // Дата утверждения
            $table->decimal('total_amount', 18, 2)->default(0); // Сумма по спецификации
            $table->json('scope_items'); // Массив описаний позиций/работ
            $table->string('status')->default('draft'); // Статус: draft|approved|archived
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specifications');
    }
}; 