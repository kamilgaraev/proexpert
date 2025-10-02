<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->foreignId('subscription_id')
                ->nullable()
                ->after('module_id')
                ->constrained('organization_subscriptions')
                ->onDelete('set null');
            
            $table->boolean('is_bundled_with_plan')
                ->default(false)
                ->after('subscription_id')
                ->comment('Модуль включён в тарифный план и синхронизирован с подпиской');
            
            $table->index(['organization_id', 'is_bundled_with_plan'], 'org_bundled_idx');
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->dropIndex('org_bundled_idx');
            $table->dropIndex(['subscription_id']);
            $table->dropForeign(['subscription_id']);
            $table->dropColumn(['subscription_id', 'is_bundled_with_plan']);
        });
    }
};
