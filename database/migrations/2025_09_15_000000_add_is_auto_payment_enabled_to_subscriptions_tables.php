<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->boolean('is_auto_payment_enabled')->default(true)->after('payment_failure_notified_at')->index();
        });

        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->boolean('is_auto_payment_enabled')->default(true)->after('payment_failure_notified_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_auto_payment_enabled');
        });

        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_auto_payment_enabled');
        });
    }
}; 