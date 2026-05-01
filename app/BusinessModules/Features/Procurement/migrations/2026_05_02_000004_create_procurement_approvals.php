<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->morphs('approvable', 'procurement_approvals_approvable_idx');
            $table->string('reason_code', 100);
            $table->string('status', 50)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->text('comment')->nullable();
            $table->json('context')->nullable()->default('{}');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_approvals');
    }
};
