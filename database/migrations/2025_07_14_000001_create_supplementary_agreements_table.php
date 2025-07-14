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
        Schema::create('supplementary_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('number'); // Номер доп. соглашения
            $table->date('agreement_date'); // Дата заключения
            $table->decimal('change_amount', 18, 2)->default(0); // Изменение суммы (+/-)
            $table->json('subject_changes'); // Массив описаний изменений
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplementary_agreements');
    }
}; 