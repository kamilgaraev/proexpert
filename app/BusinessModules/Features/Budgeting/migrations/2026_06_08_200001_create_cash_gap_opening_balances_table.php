<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_gap_opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('balance_date');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->string('status', 32)->default('draft');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->jsonb('audit_trail')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'currency', 'status', 'balance_date'], 'cash_gap_opening_balances_lookup_idx');
            $table->index(['organization_id', 'balance_date'], 'cash_gap_opening_balances_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_gap_opening_balances');
    }
};
