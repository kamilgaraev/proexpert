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
        Schema::create('journal_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('construction_journal_entries')
                ->cascadeOnDelete()
                ->comment('Запись журнала');
            $table->string('specialty')
                ->comment('Специальность/профессия');
            $table->integer('workers_count')
                ->comment('Количество рабочих');
            $table->decimal('hours_worked', 10, 2)
                ->nullable()
                ->comment('Отработано часов');
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
        Schema::dropIfExists('journal_workers');
    }
};

