<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_organization_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->text('private_notes')->nullable();
            $table->string('visibility', 16)->default('active');
            $table->timestampTz('access_revoked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['contract_id', 'organization_id']);
            $table->index(['organization_id', 'visibility', 'access_revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_organization_views');
    }
};
