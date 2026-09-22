<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_work_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('completed_work_id')->constrained('completed_works')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 128);
            $table->string('expected_version', 64);
            $table->text('reason');
            $table->string('source_event_id', 128)->nullable();
            $table->string('payload_hash', 64);
            $table->jsonb('snapshot_before');
            $table->jsonb('snapshot_after');
            $table->timestamps();
            $table->unique(['completed_work_id', 'operation_key']);
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_work_corrections');
    }
};
