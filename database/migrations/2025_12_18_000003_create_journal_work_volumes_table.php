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
        Schema::create('journal_work_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('construction_journal_entries')
                ->cascadeOnDelete()
                ->comment('Запись журнала');
            $table->foreignId('estimate_item_id')
                ->nullable()
                ->constrained('estimate_items')
                ->nullOnDelete()
                ->comment('Позиция сметы (необязательно)');
            $table->foreignId('work_type_id')
                ->nullable()
                ->constrained('work_types')
                ->nullOnDelete()
                ->comment('Вид работ');
            $table->decimal('quantity', 15, 3)
                ->comment('Объем выполненных работ');
            $table->foreignId('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete()
                ->comment('Единица измерения');
            $table->text('notes')
                ->nullable()
                ->comment('Примечания');
            $table->timestamps();

            // Индексы
            $table->index('journal_entry_id');
            $table->index('estimate_item_id');
            $table->index('work_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_work_volumes');
    }
};

