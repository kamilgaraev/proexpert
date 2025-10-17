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
        Schema::table('organizations', function (Blueprint $table) {
            // Capabilities - что организация умеет делать
            $table->json('capabilities')->nullable()->after('multi_org_settings');
            
            // Основной тип деятельности
            $table->string('primary_business_type', 100)->nullable()->after('capabilities');
            
            // Специализации
            $table->json('specializations')->nullable()->after('primary_business_type');
            
            // Сертификаты и допуски (СРО, лицензии)
            $table->json('certifications')->nullable()->after('specializations');
            
            // Заполненность профиля (0-100%)
            $table->unsignedTinyInteger('profile_completeness')->default(0)->after('certifications');
            
            // Статус онбординга
            $table->boolean('onboarding_completed')->default(false)->after('profile_completeness');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_completed');
            
            // Индексы для производительности
            $table->index('primary_business_type', 'idx_org_business_type');
            $table->index('onboarding_completed', 'idx_org_onboarding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('idx_org_business_type');
            $table->dropIndex('idx_org_onboarding');
            
            $table->dropColumn([
                'capabilities',
                'primary_business_type',
                'specializations',
                'certifications',
                'profile_completeness',
                'onboarding_completed',
                'onboarding_completed_at',
            ]);
        });
    }
};
