<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_norm_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')
                ->constrained('estimate_norm_collections')
                ->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('estimate_norm_sections')
                ->cascadeOnDelete();
            $table->string('code', 100)->nullable();
            $table->text('name');
            $table->string('section_type', 50)->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('path', 1000);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'path']);
            $table->index(['collection_id', 'parent_id']);
            $table->index('code');
            $table->index('section_type');
        });

        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->foreignId('section_id')
                ->nullable()
                ->after('collection_id')
                ->constrained('estimate_norm_sections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_id');
        });

        Schema::dropIfExists('estimate_norm_sections');
    }
};
