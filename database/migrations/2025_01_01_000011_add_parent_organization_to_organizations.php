<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('parent_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->string('organization_type')->default('single');
            $table->boolean('is_holding')->default(false);
            $table->json('multi_org_settings')->nullable();
            $table->integer('hierarchy_level')->default(0);
            $table->string('hierarchy_path')->nullable();

            $table->index(['parent_organization_id']);
            $table->index(['organization_type']);
            $table->index(['is_holding']);
            $table->index(['hierarchy_level']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['parent_organization_id']);
            $table->dropColumn([
                'parent_organization_id',
                'organization_type',
                'is_holding',
                'multi_org_settings',
                'hierarchy_level',
                'hierarchy_path'
            ]);
        });
    }
};
