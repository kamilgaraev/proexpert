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
        Schema::create('journal_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('construction_journal_entries')
                ->cascadeOnDelete()
                ->comment('Запись журнала');
            $table->foreignId('material_id')
                ->nullable()
                ->constrained('materials')
                ->nullOnDelete()
                ->comment('Материал из справочника (необязательно)');
            $table->string('material_name')
                ->comment('Название материала');
            $table->decimal('quantity', 15, 3)
                ->comment('Количество');
            $table->string('measurement_unit')
                ->comment('Единица измерения');
            $table->text('notes')
                ->nullable()
                ->comment('Примечания');
            $table->timestamps();

            // Индексы
            $table->index('journal_entry_id');
            $table->index('material_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_materials');
    }
};

