<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - создание таблицы кастомных ролей организаций.
     */
    public function up(): void
    {
        Schema::create('organization_custom_roles', function (Blueprint $table) {
            $table->id();
            
            // Организация, которой принадлежит роль
            $table->unsignedBigInteger('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            // Название и слаг роли
            $table->string('name'); // "Прораб стажер"
            $table->string('slug', 100); // "foreman_trainee"
            $table->text('description')->nullable();
            
            // Системные права (работают всегда)
            $table->json('system_permissions'); // ["profile.view", "profile.edit"]
            
            // Модульные права (работают только если модуль активирован)
            $table->json('module_permissions'); // {"projects": ["projects.view"], "materials": []}
            
            // Доступ к интерфейсам
            $table->json('interface_access'); // ["lk", "mobile", "admin"]
            
            // ABAC условия (опционально)
            $table->json('conditions')->nullable(); // {"time": "09:00-18:00", "max_budget": 500000}
            
            // Активность роли
            $table->boolean('is_active')->default(true)->index();
            
            // Кто создал роль
            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            
            // Индексы
            $table->index(['organization_id']);
            $table->index(['organization_id', 'is_active']);
            
            // Уникальность слага в рамках организации
            $table->unique(['organization_id', 'slug'], 'unique_org_role_slug');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_custom_roles');
    }
};
