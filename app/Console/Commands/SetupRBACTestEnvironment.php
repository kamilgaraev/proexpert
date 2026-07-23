<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SetupRBACTestEnvironment extends Command
{
    protected $signature = 'rbac:setup-test 
                          {--fresh : Полностью пересоздать тестовые данные}
                          {--validate : Запустить валидацию после создания}';

    protected $description = 'Быстрая настройка тестовой среды для Project-Based RBAC';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            return self::FAILURE;
        }

        $this->info('🚀 Настройка тестовой среды Project-Based RBAC...');
        $this->newLine();

        if ($this->option('fresh')) {
            if (! $this->confirm('⚠️  Это удалит ВСЕ тестовые данные. Продолжить?', false)) {
                $this->info('Операция отменена.');

                return self::SUCCESS;
            }

            $this->cleanupTestData();
        }

        // Запуск команды добавления owners
        $this->info('1️⃣  Добавление project owners...');
        Artisan::call('projects:add-owners', [], $this->getOutput());
        $this->line('  ✅ Project owners добавлены');
        $this->newLine();

        // Запуск seeder
        $this->info('2️⃣  Создание тестовых данных...');
        try {
            Artisan::call('db:seed', ['--class' => 'ProjectRBACTestSeeder'], $this->getOutput());
            $this->line('  ✅ Тестовые данные созданы');
        } catch (\Exception $e) {
            $this->error('  ❌ Ошибка при создании тестовых данных: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->newLine();

        // Валидация (если запрошена)
        if ($this->option('validate')) {
            $this->info('3️⃣  Валидация данных...');
            Artisan::call('rbac:validate', ['--verbose' => true], $this->getOutput());
            $this->newLine();
        }

        $this->displayQuickStart();

        return self::SUCCESS;
    }

    protected function cleanupTestData(): void
    {
        $this->info('🧹 Очистка старых тестовых данных...');

        // Список тестовых организаций
        $testOrganizations = [
            'ООО "СтройГенподряд"',
            'ООО "Электромонтаж"',
            'ИП "Отделка Премиум"',
            'ООО "Инвестстрой"',
            'АО "АрхитектПроект"',
            'ООО "СтройНадзор"',
        ];

        $orgIds = DB::table('organizations')
            ->whereIn('name', $testOrganizations)
            ->pluck('id')
            ->toArray();

        if (empty($orgIds)) {
            $this->line('  ℹ️  Тестовые данные не найдены');

            return;
        }

        DB::beginTransaction();

        try {
            // Удаляем связанные данные
            DB::table('completed_works')->whereIn('organization_id', $orgIds)->delete();
            DB::table('contracts')->whereIn('organization_id', $orgIds)->delete();
            DB::table('project_organization')->whereIn('organization_id', $orgIds)->delete();

            // Удаляем проекты
            $projectIds = DB::table('projects')->whereIn('organization_id', $orgIds)->pluck('id')->toArray();
            if (! empty($projectIds)) {
                DB::table('project_organization')->whereIn('project_id', $projectIds)->delete();
                DB::table('contracts')->whereIn('project_id', $projectIds)->delete();
                DB::table('completed_works')->whereIn('project_id', $projectIds)->delete();
                DB::table('projects')->whereIn('id', $projectIds)->delete();
            }

            // Удаляем пользователей
            $testEmails = [
                'director@gencontractor.ru',
                'foreman@gencontractor.ru',
                'director@electro.ru',
                'director@otdelka-premium.ru',
                'director@investstroy.ru',
            ];
            $userIds = DB::table('users')->whereIn('email', $testEmails)->pluck('id')->toArray();
            if (! empty($userIds)) {
                DB::table('user_organization')->whereIn('user_id', $userIds)->delete();
                DB::table('role_user')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }

            // Удаляем организации
            DB::table('user_organization')->whereIn('organization_id', $orgIds)->delete();
            DB::table('organizations')->whereIn('id', $orgIds)->delete();

            DB::commit();

            $this->line('  ✅ Старые тестовые данные удалены');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('  ❌ Ошибка при очистке: '.$e->getMessage());
            throw $e;
        }

        $this->newLine();
    }

    protected function displayQuickStart(): void
    {
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🎉 ТЕСТОВАЯ СРЕДА ГОТОВА!');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $this->line('📋 <fg=yellow>Тестовые аккаунты:</fg=yellow>');
        $this->line('  • director@gencontractor.ru / password - Генподрядчик');
        $this->line('  • foreman@gencontractor.ru / password - Прораб генподрядчика');
        $this->line('  • director@electro.ru / password - Субподрядчик (электрика)');
        $this->line('  • director@otdelka-premium.ru / password - Субподрядчик (отделка)');
        $this->line('  • director@investstroy.ru / password - Заказчик');
        $this->newLine();

        $this->line('🏗️  <fg=yellow>Тестовые проекты:</fg=yellow>');
        $this->line('  • ЖК "Солнечный" - 6 участников (все роли)');
        $this->line('  • ТРЦ "Мега Плаза" - 3 участника');
        $this->line('  • Бизнес-центр "Престиж" - 2 участника');
        $this->newLine();

        $this->line('🔧 <fg=yellow>Полезные команды:</fg=yellow>');
        $this->line('  • <fg=cyan>php artisan rbac:validate</fg=cyan> - валидация данных');
        $this->line('  • <fg=cyan>php artisan rbac:validate --fix</fg=cyan> - валидация с исправлением');
        $this->line('  • <fg=cyan>php artisan projects:add-owners</fg=cyan> - добавить owners');
        $this->line('  • <fg=cyan>php artisan rbac:setup-test --fresh</fg=cyan> - пересоздать тестовую среду');
        $this->newLine();

        $this->line('📡 <fg=yellow>API Endpoints для тестирования:</fg=yellow>');
        $this->line('  • <fg=green>GET</fg=green> /api/v1/landing/organization/profile');
        $this->line('  • <fg=green>GET</fg=green> /api/v1/landing/my-projects');
        $this->line('  • <fg=green>GET</fg=green> /api/v1/admin/projects/{project}/context');
        $this->line('  • <fg=green>GET</fg=green> /api/v1/admin/projects/{project}/participants');
        $this->line('  • <fg=blue>POST</fg=blue> /api/v1/admin/projects/{project}/contracts');
        $this->newLine();

        $this->info('✅ Готово к тестированию!');
    }
}
