<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role; // Импортируем модель
use Illuminate\Support\Facades\DB; // Для вывода информации

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Используем DB::table для надежности, если модель использует firstOrCreate с багами или кастомной логикой
        // Очистка таблицы для идемпотентности (опционально, если нужно чистое состояние)
        // DB::table('permissions')->truncate(); // Если есть таблица разрешений
        // DB::table('roles')->truncate(); // Осторожно! Удалит все роли
        // DB::table('role_user')->truncate(); // Осторожно! Удалит все назначения ролей
        // DB::table('permission_role')->truncate(); // Если есть таблица связи разрешений и ролей

        $this->command->info('Creating roles...');

        $roles = [
            ['slug' => Role::ROLE_SYSTEM_ADMIN, 'name' => 'Системный администратор',    'type' => Role::TYPE_SYSTEM],
            ['slug' => Role::ROLE_OWNER,        'name' => 'Владелец организации',       'type' => Role::TYPE_SYSTEM],
            ['slug' => Role::ROLE_ADMIN,        'name' => 'Администратор организации',  'type' => Role::TYPE_SYSTEM],
            ['slug' => Role::ROLE_FOREMAN,      'name' => 'Прораб',                     'type' => Role::TYPE_SYSTEM],
            // --- Добавляем новые роли для админ-панели ---
            ['slug' => 'web_admin',           'name' => 'Администратор Панели',     'type' => Role::TYPE_SYSTEM],
            ['slug' => 'accountant',          'name' => 'Бухгалтер',                'type' => Role::TYPE_SYSTEM],
            // ----------------------------------------------
        ];

        foreach ($roles as $roleData) {
            // Добавляем 'description', если нужно
            $roleData['description'] = $roleData['description'] ?? 'Системная роль';
            Role::updateOrCreate(
                ['slug' => $roleData['slug']], // Поиск по уникальному slug
                $roleData // Данные для создания или обновления
            );
            $this->command->line('Created/Updated role: ' . $roleData['name']);
        }

        $this->command->info('Roles seeded.');

        // TODO: Добавить создание разрешений (Permissions) и привязку их к ролям
        // Например:
        // $this->command->info('Creating permissions...');
        // $permissions = [ ... ];
        // foreach ($permissions as $permData) { Permission::updateOrCreate(...); }
        // $this->command->info('Permissions seeded.');
        //
        // $this->command->info('Attaching permissions to roles...');
        // $adminRole = Role::where('slug', Role::ROLE_ADMIN)->first();
        // $ownerRole = Role::where('slug', Role::ROLE_OWNER)->first();
        // ... найти нужные permissions ...
        // $adminRole->permissions()->syncWithoutDetaching([...]);
        // $ownerRole->permissions()->syncWithoutDetaching([...]);
        // $this->command->info('Permissions attached.');
    }
}
