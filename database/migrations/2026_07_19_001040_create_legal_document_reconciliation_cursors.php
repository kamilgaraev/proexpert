<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_reconciliation_cursors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->default(0);
            $table->string('source', 64);
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_reconciliation_cursors');
    }
};
