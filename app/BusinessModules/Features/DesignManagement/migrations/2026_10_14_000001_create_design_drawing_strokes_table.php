<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_artifact_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('drawing_revision')->default(0);
        });
        Schema::create('design_drawing_strokes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->unsignedInteger('page_number')->nullable();
            $table->jsonb('annotations')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->unsignedBigInteger('revision');
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['version_id', 'client_id'], 'drawing_strokes_client_unique');
            $table->unique(['version_id', 'revision'], 'drawing_strokes_revision_unique');
            $table->index(['version_id', 'author_id', 'page_number'], 'drawing_strokes_author_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_drawing_strokes');
        Schema::table('design_artifact_versions', function (Blueprint $table): void {
            $table->dropColumn('drawing_revision');
        });
    }
};
