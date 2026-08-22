<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('construction_journal_entries')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 32);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['journal_entry_id', 'occurred_at'], 'journal_entry_approval_events_timeline_index');
            $table->index(['organization_id', 'project_id'], 'journal_entry_approval_events_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_approval_events');
    }
};
