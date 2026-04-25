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
            $table->foreignId('subscription_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('organization_subscriptions')
                ->onDelete('set null');

            $table->boolean('is_bundled_with_plan')
                ->default(false)
                ->after('subscription_id');

            $table->index(['organization_id', 'is_bundled_with_plan'], 'org_package_bundled_idx');
            $table->index('subscription_id', 'org_package_subscription_idx');
        });
    }

    public function down(): void
    {
        Schema::table('organization_package_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropIndex('org_package_subscription_idx');
            $table->dropIndex('org_package_bundled_idx');
            $table->dropColumn(['subscription_id', 'is_bundled_with_plan']);
        });
    }
};
