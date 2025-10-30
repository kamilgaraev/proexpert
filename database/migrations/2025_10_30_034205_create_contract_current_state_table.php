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
        Schema::create('contract_current_state', function (Blueprint $table) {
            $table->foreignId('contract_id')->primary()->constrained('contracts')->onDelete('cascade');
            $table->foreignId('active_specification_id')->nullable()->constrained('specifications')->onDelete('set null');
            $table->decimal('current_total_amount', 18, 2)->default(0);
            $table->jsonb('active_events')->nullable(); // Массив ID активных событий
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index('active_specification_id');
        });

        // GIN индекс для JSONB поля active_events в PostgreSQL
        DB::statement('CREATE INDEX contract_current_state_active_events_gin_idx ON contract_current_state USING GIN(active_events)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_current_state_active_events_gin_idx');
        Schema::dropIfExists('contract_current_state');
    }
};
