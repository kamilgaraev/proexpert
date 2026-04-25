<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_act_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('performance_act_id')->constrained('contract_performance_acts')->cascadeOnDelete();
            $table->foreignId('completed_work_id')->nullable()->constrained('completed_works')->nullOnDelete();
            $table->foreignId('estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->string('line_type', 32);
            $table->string('title');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->text('manual_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['performance_act_id', 'line_type'], 'performance_act_lines_act_type_idx');
            $table->index('completed_work_id', 'performance_act_lines_completed_work_idx');
        });

        DB::statement(
            "ALTER TABLE performance_act_lines ADD CONSTRAINT performance_act_lines_line_type_check CHECK (line_type IN ('completed_work', 'manual'))"
        );
        DB::statement(
            'ALTER TABLE performance_act_lines ADD CONSTRAINT performance_act_lines_quantity_positive_check CHECK (quantity > 0)'
        );
        DB::statement(
            'ALTER TABLE performance_act_lines ADD CONSTRAINT performance_act_lines_amount_non_negative_check CHECK (amount >= 0)'
        );
        DB::statement(
            'ALTER TABLE performance_act_lines ADD CONSTRAINT performance_act_lines_unit_price_non_negative_check CHECK (unit_price IS NULL OR unit_price >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_act_lines');
    }
};
