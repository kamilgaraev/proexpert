<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::create('estimate_operation_price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('name');
            $table->string('currency', 3);
            $table->decimal('vat_rate', 5, 2);
            $table->boolean('includes_vat');
            $table->date('effective_date');
            $table->string('file_path');
            $table->string('checksum', 64);
            $table->jsonb('entries');
            $table->timestamps();
            $table->index(['organization_id', 'project_id']);
        });
        Schema::create('estimate_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('mode', 20);
            $table->string('status', 30);
            $table->unsignedInteger('generation')->default(1);
            $table->string('apply_key', 100)->nullable();
            $table->jsonb('parameters');
            $table->jsonb('source_snapshot')->nullable();
            $table->jsonb('resolutions')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('result_ids')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'actor_id', 'created_at']);
        });
        Schema::create('estimate_operation_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('estimate_operations')->cascadeOnDelete();
            $table->string('row_key', 100);
            $table->unsignedInteger('position');
            $table->boolean('has_conflicts')->default(false);
            $table->jsonb('payload');
            $table->unique(['operation_id', 'row_key']);
            $table->index(['operation_id', 'has_conflicts', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_operation_rows');
        Schema::dropIfExists('estimate_operations');
        Schema::dropIfExists('estimate_operation_price_lists');
    }
};
