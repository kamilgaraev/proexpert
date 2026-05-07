<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_dataset_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 50);
            $table->string('version_key', 100);
            $table->string('bucket');
            $table->string('prefix');
            $table->string('status', 50)->default('created');
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('errors_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'version_key']);
            $table->index('status');
        });

        Schema::create('construction_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_version_id')
                ->constrained('estimate_dataset_versions')
                ->cascadeOnDelete();
            $table->string('ksr_code', 100);
            $table->text('name');
            $table->string('unit', 50)->nullable();
            $table->string('resource_type', 50);
            $table->string('okpd2_code', 100)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['dataset_version_id', 'ksr_code']);
            $table->index('ksr_code');
            $table->index('resource_type');
            $table->fullText('name');
        });

        Schema::create('estimate_norm_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_version_id')
                ->constrained('estimate_dataset_versions')
                ->cascadeOnDelete();
            $table->string('code', 100);
            $table->text('name');
            $table->string('norm_type', 50);
            $table->string('source_file');
            $table->timestamps();

            $table->unique(['dataset_version_id', 'code', 'norm_type']);
            $table->index('norm_type');
        });

        Schema::create('estimate_norms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')
                ->constrained('estimate_norm_collections')
                ->cascadeOnDelete();
            $table->string('code', 100);
            $table->text('name');
            $table->string('unit', 50)->nullable();
            $table->string('section_code', 100)->nullable();
            $table->text('section_name')->nullable();
            $table->json('work_composition')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'code']);
            $table->index('code');
            $table->index('section_code');
            $table->fullText('name');
        });

        Schema::create('estimate_norm_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_norm_id')
                ->constrained('estimate_norms')
                ->cascadeOnDelete();
            $table->foreignId('construction_resource_id')
                ->nullable()
                ->constrained('construction_resources')
                ->nullOnDelete();
            $table->string('resource_code', 100)->nullable();
            $table->text('resource_name')->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 20, 6)->nullable();
            $table->string('resource_type', 50)->default('other');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('resource_code');
            $table->index('resource_type');
            $table->index(['estimate_norm_id', 'resource_code']);
        });

        Schema::create('estimate_resource_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_version_id')
                ->constrained('estimate_dataset_versions')
                ->cascadeOnDelete();
            $table->foreignId('construction_resource_id')
                ->nullable()
                ->constrained('construction_resources')
                ->nullOnDelete();
            $table->string('resource_code', 100);
            $table->text('resource_name')->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('base_price', 20, 4)->nullable();
            $table->string('price_type', 50)->default('other');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['dataset_version_id', 'resource_code', 'price_type']);
            $table->index('resource_code');
            $table->index('price_type');
        });

        Schema::create('estimate_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_version_id')
                ->constrained('estimate_dataset_versions')
                ->cascadeOnDelete();
            $table->string('source_file');
            $table->unsignedBigInteger('row_number')->nullable();
            $table->string('node_path')->nullable();
            $table->string('severity', 50);
            $table->text('message');
            $table->json('raw_fragment')->nullable();
            $table->timestamps();

            $table->index(['dataset_version_id', 'severity']);
            $table->index('source_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_import_errors');
        Schema::dropIfExists('estimate_resource_prices');
        Schema::dropIfExists('estimate_norm_resources');
        Schema::dropIfExists('estimate_norms');
        Schema::dropIfExists('estimate_norm_collections');
        Schema::dropIfExists('construction_resources');
        Schema::dropIfExists('estimate_dataset_versions');
    }
};
