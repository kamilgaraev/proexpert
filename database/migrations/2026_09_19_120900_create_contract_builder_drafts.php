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
        Schema::create('contract_builder_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_revision_id');
            $table->unsignedInteger('version');
            $table->jsonb('document');
            $table->jsonb('values');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['instance_id', 'organization_id']);
            $table->foreign(['base_revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
        });
        Schema::create('contract_builder_draft_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('contract_builder_drafts')->restrictOnDelete();
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->jsonb('response');
            $table->timestampTz('created_at');
            $table->unique(['draft_id', 'request_key']);
        });
        DB::statement("ALTER TABLE contract_builder_drafts ADD CONSTRAINT contract_builder_draft_shape CHECK (version > 0 AND jsonb_typeof(document) = 'object' AND jsonb_typeof(values) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_builder_draft_operations');
        Schema::dropIfExists('contract_builder_drafts');
    }
};
