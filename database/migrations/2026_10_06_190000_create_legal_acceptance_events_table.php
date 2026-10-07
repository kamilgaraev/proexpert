<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptance_events', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->string('event_key', 64)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('document_key', 50);
            $table->string('version', 50);
            $table->string('document_sha256', 64);
            $table->string('action', 30);
            $table->string('source', 100);
            $table->string('reference', 128);
            $table->timestampTz('recorded_at');
            $table->jsonb('snapshot');
            $table->jsonb('evidence');
            $table->string('proof_hmac', 64);
            $table->index(['organization_id', 'document_key', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptance_events');
    }
};
