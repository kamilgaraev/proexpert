<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_proposal_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_request_id')->constrained('supplier_requests')->cascadeOnDelete();
            $table->foreignId('winning_supplier_proposal_id')
                ->nullable()
                ->constrained('supplier_proposals')
                ->nullOnDelete();
            $table->foreignId('cheapest_supplier_proposal_id')
                ->nullable()
                ->constrained('supplier_proposals')
                ->nullOnDelete();
            $table->string('status', 50)->default('draft');
            $table->boolean('is_lowest_price_selected')->default(false);
            $table->text('decision_reason')->nullable();
            $table->json('comparison_snapshot')->nullable()->default('[]');
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->unique('supplier_request_id');
            $table->index(['organization_id', 'status']);
            $table->index('supplier_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_proposal_decisions');
    }
};
