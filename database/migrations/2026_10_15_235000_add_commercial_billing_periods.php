<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_commercial_accounts', function (Blueprint $table): void {
            $table->string('billing_period', 20)->default('30_days');
        });
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->string('billing_period', 20)->default('30_days');
            $table->boolean('renew_subscription')->default(false);
            $table->jsonb('paid_period_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->dropColumn(['billing_period', 'renew_subscription', 'paid_period_snapshot']);
        });
        Schema::table('organization_commercial_accounts', function (Blueprint $table): void {
            $table->dropColumn('billing_period');
        });
    }
};
