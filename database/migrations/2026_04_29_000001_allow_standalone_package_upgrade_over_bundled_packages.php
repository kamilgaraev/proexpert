<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_package_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'package_slug']);
            $table->unique(
                ['organization_id', 'package_slug', 'is_bundled_with_plan'],
                'org_package_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('organization_package_subscriptions', function (Blueprint $table) {
            $table->dropUnique('org_package_source_unique');
            $table->unique(['organization_id', 'package_slug']);
        });
    }
};
