<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_access_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('granted_to_organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('target_organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('resource_type');
            $table->json('permissions')->nullable();
            $table->enum('access_level', ['read', 'write', 'admin', 'full'])->default('read');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            
            $table->unique(['granted_to_organization_id', 'target_organization_id', 'resource_type'], 'org_access_unique');
            $table->index(['granted_to_organization_id']);
            $table->index(['target_organization_id']);
            $table->index(['resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_access_permissions');
    }
}; 