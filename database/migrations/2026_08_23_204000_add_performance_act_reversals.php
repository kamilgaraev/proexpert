<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->timestampTz('annulled_at')->nullable()->after('rejection_reason');
            $table->foreignId('annulled_by_user_id')
                ->nullable()
                ->after('annulled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('annulment_reason')->nullable()->after('annulled_by_user_id');
        });

        Schema::create('performance_act_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('performance_act_id')->constrained('contract_performance_acts')->restrictOnDelete();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_status', 32);
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->jsonb('invoice_ids')->default('[]');
            $table->string('idempotency_key', 128);
            $table->timestampTz('reversed_at');
            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key'], 'performance_act_reversals_idempotency_unique');
            $table->unique('performance_act_id', 'performance_act_reversals_act_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_act_reversals');

        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('annulled_by_user_id');
            $table->dropColumn(['annulled_at', 'annulment_reason']);
        });
    }
};
