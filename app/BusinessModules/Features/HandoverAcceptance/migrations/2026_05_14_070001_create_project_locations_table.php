<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_locations')->cascadeOnDelete();
            $table->string('location_type', 40);
            $table->string('name');
            $table->string('code', 80)->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'location_type']);
            $table->unique(['organization_id', 'project_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_locations');
    }
};
