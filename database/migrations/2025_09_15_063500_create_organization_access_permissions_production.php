<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organization_access_permissions')) {
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
                $table->index(['is_active']);
            });
        } else {
            Schema::table('organization_access_permissions', function (Blueprint $table) {
                if (!Schema::hasColumn('organization_access_permissions', 'granted_to_organization_id')) {
                    $table->dropColumn(['organization_id', 'permission_type', 'permission_value', 'is_granted', 'metadata']);
                    
                    $table->foreignId('granted_to_organization_id')->constrained('organizations')->onDelete('cascade');
                    $table->foreignId('target_organization_id')->constrained('organizations')->onDelete('cascade');
                    $table->string('resource_type');
                    $table->json('permissions')->nullable();
                    $table->enum('access_level', ['read', 'write', 'admin', 'full'])->default('read');
                    $table->boolean('is_active')->default(true);
                    $table->timestamp('expires_at')->nullable();
                    $table->foreignId('granted_by_user_id')->constrained('users')->onDelete('restrict');
                    
                    $table->unique(['granted_to_organization_id', 'target_organization_id', 'resource_type'], 'org_access_unique');
                    $table->index(['granted_to_organization_id']);
                    $table->index(['target_organization_id']);
                    $table->index(['resource_type']);
                    $table->index(['is_active']);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_access_permissions');
    }
};
