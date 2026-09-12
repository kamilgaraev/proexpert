<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table): void {
            $table->unsignedBigInteger('finance_revision')->default(0);
        });
        Schema::table('estimate_item_resources', function (Blueprint $table): void {
            $table->string('finance_representation', 20)->default('unreviewed');
            $table->string('finance_unit_label')->nullable();
            $table->string('finance_source_hash', 64)->nullable();
            $table->foreignId('represented_by_item_id')->nullable()->constrained('estimate_items')->restrictOnDelete();
        });
        Schema::table('contract_estimate_items', function (Blueprint $table): void {
            $table->boolean('finance_managed')->default(false);
            $table->decimal('quantity', 20, 8)->nullable()->change();
            $table->decimal('amount', 20, 2)->nullable()->change();
            $table->decimal('amount_without_vat', 20, 2)->nullable()->change();
        });
        Schema::create('estimate_finance_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('estimate_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('estimate_item_resources')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contract_estimate_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('side', 16);
            $table->string('source', 20);
            $table->string('currency', 3);
            $table->decimal('quantity', 20, 8);
            $table->decimal('unit_price', 20, 8)->nullable();
            $table->decimal('amount_without_vat', 20, 2)->nullable();
            $table->decimal('amount_with_vat', 20, 2)->nullable();
            $table->decimal('vat_rate', 7, 4)->nullable();
            $table->decimal('legacy_amount', 20, 2)->nullable();
            $table->string('price_basis', 16);
            $table->string('method', 16);
            $table->boolean('composition_confirmed')->default(false);
            $table->jsonb('estimate_snapshot');
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['estimate_id', 'estimate_item_id']);
        });
        Schema::create('estimate_finance_mutations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('estimate_id');
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('revision');
            $table->jsonb('changes');
            $table->timestamps();
            $table->unique(['estimate_id', 'mutation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_finance_mutations');
        Schema::dropIfExists('estimate_finance_allocations');
        Schema::table('estimate_item_resources', function (Blueprint $table): void {
            $table->dropForeign(['represented_by_item_id']);
            $table->dropColumn(['finance_representation', 'represented_by_item_id', 'finance_unit_label', 'finance_source_hash']);
        });
        Schema::table('contract_estimate_items', fn (Blueprint $table) => $table->dropColumn('finance_managed'));
        Schema::table('estimates', fn (Blueprint $table) => $table->dropColumn('finance_revision'));
    }
};
