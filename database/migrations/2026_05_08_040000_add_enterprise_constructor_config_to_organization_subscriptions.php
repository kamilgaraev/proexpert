<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('organization_subscriptions', 'enterprise_constructor_config')) {
                $table->json('enterprise_constructor_config')->nullable()->after('is_auto_payment_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('organization_subscriptions', 'enterprise_constructor_config')) {
                $table->dropColumn('enterprise_constructor_config');
            }
        });
    }
};
