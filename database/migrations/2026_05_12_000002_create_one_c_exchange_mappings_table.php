<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_exchange_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 64);
            $table->string('external_id', 191);
            $table->string('external_name')->nullable();
            $table->string('local_type', 64);
            $table->unsignedBigInteger('local_id');
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'scope', 'external_id'], 'one_c_mapping_external_unique');
            $table->index(['organization_id', 'scope', 'local_type', 'local_id'], 'one_c_mapping_local_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_exchange_mappings');
    }
};
