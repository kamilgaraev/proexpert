<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - удаление старой системы авторизации.
     */
    public function up(): void
    {
        // Отключаем проверку внешних ключей
        Schema::disableForeignKeyConstraints();

        // 1. Удаляем таблицы с зависимостями на roles первыми
        Schema::dropIfExists('landing_admin_role'); // Зависит на roles
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('organization_role_user');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permission_role'); // на случай если есть

        // 2. Удаляем основные таблицы ролей и разрешений
        Schema::dropIfExists('organization_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('organization_access_permissions');

        // 3. Удаляем связанные колонки из users таблицы (если есть)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Удаляем колонки, связанные со старой системой авторизации
                $table->dropColumn([
                    'user_type', // Если есть
                    'role_id',   // Если есть прямая связь с ролью
                ]);
            });
        }

        // Включаем обратно проверку внешних ключей
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations - восстановление (на всякий случай).
     * 
     * ВНИМАНИЕ: Это только структура таблиц! Данные будут потеряны!
     */
    public function down(): void
    {
        // Создаем базовую структуру таблиц обратно (для экстренного отката)
        
        // Роли
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('system');
            $table->boolean('is_active')->default(true);
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // Разрешения
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('group')->nullable();
            $table->timestamps();
        });

        // Организационные роли
        Schema::create('organization_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        // Связь пользователей с ролями
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'organization_id']);
        });

        // Связь пользователей с организационными ролями
        Schema::create('organization_role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_role_id')->constrained('organization_roles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['organization_id', 'organization_role_id', 'user_id'], 'org_role_user_unique');
        });

        // Права доступа организации
        Schema::create('organization_access_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('permission_type');
            $table->string('permission_value');
            $table->boolean('is_granted')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'permission_type', 'permission_value'], 'org_permission_unique');
            $table->index(['organization_id', 'is_granted']);
        });
    }
};
