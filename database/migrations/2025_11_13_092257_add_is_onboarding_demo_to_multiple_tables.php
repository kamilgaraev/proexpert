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
        // Добавляем поле is_onboarding_demo для отметки демо-данных обучающего тура
        
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('is_archived');
            $table->index('is_onboarding_demo');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('is_verified');
            $table->index('is_onboarding_demo');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('project_schedules', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('project_events', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });

        Schema::table('completed_works', function (Blueprint $table) {
            $table->boolean('is_onboarding_demo')->default(false)->after('deleted_at');
            $table->index('is_onboarding_demo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('project_schedules', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('project_events', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });

        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropIndex(['is_onboarding_demo']);
            $table->dropColumn('is_onboarding_demo');
        });
    }
};
