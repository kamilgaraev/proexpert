<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('event_type', 100);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supplier_party_id')->nullable()->constrained('supplier_parties')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->jsonb('payload')->nullable()->default('{}');
            $table->timestamps();

            $table->index(['organization_id', 'event_type'], 'procurement_audit_events_org_type_idx');
            $table->index(['subject_type', 'subject_id', 'organization_id'], 'procurement_audit_events_subject_idx');
            $table->index('supplier_party_id', 'procurement_audit_events_supplier_party_idx');
            $table->index('occurred_at', 'procurement_audit_events_occurred_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_audit_events');
    }
};
