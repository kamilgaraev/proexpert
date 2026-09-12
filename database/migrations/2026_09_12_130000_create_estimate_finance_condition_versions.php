<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('condition_version')->default(1);
        });
        Schema::create('estimate_finance_condition_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $table->uuid('allocation_key');
            $table->unsignedInteger('condition_version');
            $table->unsignedBigInteger('finance_revision');
            $table->uuid('mutation_id');
            $table->string('action', 16);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['allocation_key', 'condition_version'], 'estimate_finance_conditions_key_version_unique');
            $table->index(['estimate_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_finance_condition_versions');
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->dropColumn('condition_version');
        });
    }
};
