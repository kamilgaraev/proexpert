<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Шаг 1: Добавить новую колонку role_new VARCHAR(50)
        Schema::table('project_organization', function (Blueprint $table) {
            $table->string('role_new', 50)->nullable()->after('role');
        });
        
        // Шаг 2: Копировать данные из role в role_new
        DB::statement('UPDATE project_organization SET role_new = role');
        
        // Шаг 3: Мигрировать child_contractor → subcontractor
        DB::table('project_organization')
            ->where('role_new', 'child_contractor')
            ->update(['role_new' => 'subcontractor']);
        
        // Шаг 4: Добавить новые колонки
        Schema::table('project_organization', function (Blueprint $table) {
            // Статус участия
            $table->boolean('is_active')->default(true)->after('permissions');
            
            // Мета-информация о приглашении
            $table->foreignId('added_by_user_id')->nullable()
                ->after('is_active')
                ->constrained('users')
                ->onDelete('set null');
            
            $table->timestamp('invited_at')->nullable()->after('added_by_user_id');
            $table->timestamp('accepted_at')->nullable()->after('invited_at');
            
            // JSON metadata для расширяемости
            $table->json('metadata')->nullable()->after('accepted_at');
        });
        
        // Шаг 5: Заполнить новые поля для существующих записей
        DB::table('project_organization')
            ->whereNull('is_active')
            ->update([
                'is_active' => true,
                'invited_at' => DB::raw('created_at'),
                'accepted_at' => DB::raw('created_at'),
            ]);
        
        // Шаг 6: Создать индексы
        Schema::table('project_organization', function (Blueprint $table) {
            $table->index(['project_id', 'role_new'], 'idx_project_role_new');
            $table->index(['organization_id', 'is_active'], 'idx_org_active');
            $table->index('is_active', 'idx_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_organization', function (Blueprint $table) {
            // Удалить индексы
            $table->dropIndex('idx_project_role_new');
            $table->dropIndex('idx_org_active');
            $table->dropIndex('idx_active');
            
            // Удалить foreign key constraint
            $table->dropForeign(['added_by_user_id']);
            
            // Удалить новые колонки
            $table->dropColumn([
                'role_new',
                'is_active',
                'added_by_user_id',
                'invited_at',
                'accepted_at',
                'metadata',
            ]);
        });
        
        // Откат миграции child_contractor
        DB::table('project_organization')
            ->where('role', 'subcontractor')
            ->update(['role' => 'child_contractor']);
    }
};
