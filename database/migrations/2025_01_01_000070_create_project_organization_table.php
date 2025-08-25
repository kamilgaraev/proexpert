<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_organization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['owner', 'contractor', 'child_contractor', 'observer'])->default('contractor');
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'organization_id'], 'project_organization_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_organization');
    }
};
