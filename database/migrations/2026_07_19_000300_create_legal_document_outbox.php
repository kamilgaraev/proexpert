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
        if (Schema::hasTable('legal_document_outbox')) {
            return;
        }

        Schema::create('legal_document_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('aggregate_type', 120);
            $table->string('aggregate_id', 120);
            $table->string('event', 160);
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->string('idempotency_key', 191);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->timestampTz('reconciliation_required_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'aggregate_type', 'aggregate_id', 'idempotency_key'],
                'legal_document_outbox_idempotency_unique',
            );
            $table->index(
                ['published_at', 'dead_lettered_at', 'available_at'],
                'legal_document_outbox_pending_idx',
            );
            $table->index(
                ['organization_id', 'reconciliation_required_at'],
                'legal_document_outbox_reconcile_idx',
            );
            $table->index(['claim_token', 'claimed_at'], 'legal_document_outbox_claim_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
ALTER TABLE legal_document_outbox
ADD CONSTRAINT legal_document_outbox_attempts_check CHECK (attempts <= 100),
ADD CONSTRAINT legal_document_outbox_publication_check CHECK (
    NOT (published_at IS NOT NULL AND dead_lettered_at IS NOT NULL)
)
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_outbox');
    }
};
