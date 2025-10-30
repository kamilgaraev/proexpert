<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_state_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->string('event_type', 50); // created, amended, superseded, cancelled
            $table->string('triggered_by_type', 255)->nullable(); // Contract, SupplementaryAgreement
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->foreignId('specification_id')->nullable()->constrained('specifications')->onDelete('set null');
            $table->decimal('amount_delta', 18, 2)->default(0); // Изменение суммы: +100000 или -100000
            $table->date('effective_from')->nullable(); // Дата вступления в силу
            $table->foreignId('supersedes_event_id')->nullable()->constrained('contract_state_events')->onDelete('set null');
            $table->jsonb('metadata')->nullable(); // {reason, initiator, comment, etc}
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Индексы
            $table->index('contract_id');
            $table->index('event_type');
            $table->index('supersedes_event_id');
            $table->index(['triggered_by_type', 'triggered_by_id']);
            $table->index('effective_from');
        });

        // GIN индекс для JSONB поля metadata в PostgreSQL
        DB::statement('CREATE INDEX contract_state_events_metadata_gin_idx ON contract_state_events USING GIN(metadata)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_state_events_metadata_gin_idx');
        Schema::dropIfExists('contract_state_events');
    }
};
