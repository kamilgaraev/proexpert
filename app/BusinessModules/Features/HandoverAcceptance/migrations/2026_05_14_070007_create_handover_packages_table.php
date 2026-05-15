<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'acceptance_scope_id']);
        });

        Schema::create('handover_package_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('handover_package_id')->constrained('handover_packages')->cascadeOnDelete();
            $table->string('title');
            $table->string('document_type', 80)->default('executive_document');
            $table->boolean('is_required')->default(true);
            $table->string('status', 40)->default('missing');
            $table->string('external_url')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_package_documents');
        Schema::dropIfExists('handover_packages');
    }
};
