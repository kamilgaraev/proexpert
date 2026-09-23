<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_organization_hierarchy', function (Blueprint $table): void {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->timestamps();

            $table->primary(['project_id', 'organization_id'], 'project_org_hierarchy_primary');
            $table->foreign(['project_id', 'parent_organization_id'], 'project_org_hierarchy_parent_fk')
                ->references(['project_id', 'organization_id'])
                ->on('project_organization_hierarchy')
                ->cascadeOnDelete();
            $table->index(['project_id', 'parent_organization_id'], 'project_org_hierarchy_parent_index');
        });
        DB::statement('ALTER TABLE project_organization_hierarchy ADD CONSTRAINT project_org_hierarchy_no_self_parent CHECK (parent_organization_id IS NULL OR parent_organization_id <> organization_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_organization_hierarchy');
    }
};
