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
        Schema::create('journal_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('construction_journal_entries')
                ->cascadeOnDelete()
                ->comment('Запись журнала');
            $table->string('equipment_name')
                ->comment('Название оборудования');
            $table->string('equipment_type')
                ->nullable()
                ->comment('Тип оборудования');
            $table->integer('quantity')
                ->default(1)
                ->comment('Количество единиц');
            $table->decimal('hours_used', 10, 2)
                ->nullable()
                ->comment('Часов использования');
            $table->timestamps();

            // Индексы
            $table->index('journal_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_equipment');
    }
};

