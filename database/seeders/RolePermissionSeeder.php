<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role; // Импортируем модель
use App\Models\Permission; // Импортируем модель Permission
use Illuminate\Support\Facades\DB; // Для вывода информации

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Очистка таблиц для идемпотентности (осторожно!)
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;'); // Отключаем проверку внешних ключей
        // DB::table('permission_role')->truncate();
        // DB::table('permissions')->truncate();
        // DB::table('roles')->truncate();
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;'); // Включаем обратно

        $this->command->info('Creating roles...');

        $rolesData = [
            ['slug' => 'super_admin', 'name' => 'Главный администратор', 'type' => Role::TYPE_SYSTEM, 'description' => 'Полный контроль над системой.'],
            ['slug' => 'admin', 'name' => 'Администратор', 'type' => Role::TYPE_SYSTEM, 'description' => 'Администрирование системы.'],
            ['slug' => 'content_admin', 'name' => 'Администратор контента', 'type' => Role::TYPE_SYSTEM, 'description' => 'Управление контентом.'],
            ['slug' => 'support_admin', 'name' => 'Администратор поддержки', 'type' => Role::TYPE_SYSTEM, 'description' => 'Поддержка пользователей.'],
            ['slug' => Role::ROLE_SYSTEM_ADMIN, 'name' => 'Системный администратор',    'type' => Role::TYPE_SYSTEM, 'description' => 'Полный доступ ко всей системе.'],
            ['slug' => Role::ROLE_OWNER,        'name' => 'Владелец организации',       'type' => Role::TYPE_SYSTEM, 'description' => 'Полный доступ к своей организации.'],
            ['slug' => Role::ROLE_ADMIN,        'name' => 'Администратор организации',  'type' => Role::TYPE_SYSTEM, 'description' => 'Управление пользователями и настройками организации.'],
            ['slug' => Role::ROLE_FOREMAN,      'name' => 'Прораб',                     'type' => Role::TYPE_SYSTEM, 'description' => 'Пользователь мобильного приложения, управляет работами на объектах.'],
            ['slug' => 'web_admin',           'name' => 'Администратор Панели',     'type' => Role::TYPE_SYSTEM, 'description' => 'Управление контентом и пользователями через веб-панель.'],
            ['slug' => 'accountant',          'name' => 'Бухгалтер',                'type' => Role::TYPE_SYSTEM, 'description' => 'Доступ к финансовым отчетам и операциям.'],
        ];

        foreach ($rolesData as $roleItem) {
            Role::updateOrCreate(['slug' => $roleItem['slug']], $roleItem);
            $this->command->line('Created/Updated role: ' . $roleItem['name']);
        }
        $this->command->info('Roles seeded.');

        $this->command->info('Creating permissions...');
        $permissionsData = [
            // General Admin Access
            ['slug' => 'admin.access', 'name' => 'Доступ к Админ-панели', 'description' => 'Общее право доступа к административной части портала.'],
            
            // Foreman Management
            ['slug' => 'admin.users.foremen.view', 'name' => 'Просмотр Прорабов', 'description' => 'Просмотр списка прорабов и их данных.'],
            ['slug' => 'admin.users.foremen.manage', 'name' => 'Управление Прорабами', 'description' => 'Создание, редактирование, удаление, блокировка/разблокировка прорабов.'],
            
            // Organization Admin Management (limited to avoid self-management issues by non-owners)
            ['slug' => 'admin.users.org_admins.view', 'name' => 'Просмотр Администраторов Организации', 'description' => 'Просмотр администраторов организации.'],
            // ['slug' => 'admin.users.org_admins.manage', 'name' => 'Управление Администраторами Организации', 'description' => 'Создание, редактирование администраторов организации (кроме владельца).'],

            // Web Admin Management
            ['slug' => 'admin.users.web_admins.view', 'name' => 'Просмотр Администраторов Панели', 'description' => 'Просмотр пользователей с ролью "Администратор Панели".'],
            ['slug' => 'admin.users.web_admins.manage', 'name' => 'Управление Администраторами Панели', 'description' => 'Управление пользователями с ролью "Администратор Панели".'],

            // Accountant Management
            ['slug' => 'admin.users.accountants.view', 'name' => 'Просмотр Бухгалтеров', 'description' => 'Просмотр пользователей с ролью "Бухгалтер".'],
            ['slug' => 'admin.users.accountants.manage', 'name' => 'Управление Бухгалтерами', 'description' => 'Управление пользователями с ролью "Бухгалтер".'],
            
            // Advance Accounting
            ['slug' => 'admin.advance_accounting.view', 'name' => 'Просмотр Подотчетных Средств', 'description' => 'Просмотр балансов и транзакций подотчетных средств пользователей.'],
            ['slug' => 'admin.advance_accounting.manage', 'name' => 'Управление Подотчетными Средствами', 'description' => 'Выдача и возврат подотчетных средств.'],
        ];

        foreach ($permissionsData as $permissionItem) {
            Permission::updateOrCreate(['slug' => $permissionItem['slug']], $permissionItem);
            $this->command->line('Created/Updated permission: ' . $permissionItem['name']);
        }
        $this->command->info('Permissions seeded.');

        $this->command->info('Attaching permissions to roles...');

        // Define permissions for each role
        $systemAdminPermissions = Permission::pluck('slug')->toArray(); // Sys admin gets all
        $adminPanelPermissions = Permission::where('slug', 'like', 'admin.%')->pluck('slug')->toArray();
        $ownerPermissions = $systemAdminPermissions; // Owner also gets all within their org context implicitly

        $adminPermissions = [
            'admin.access',
            'admin.users.foremen.view',
            'admin.users.foremen.manage',
            'admin.users.org_admins.view', // Can view other admins
            // 'admin.users.org_admins.manage', // Can manage other admins (excluding owner)
            'admin.users.web_admins.view',
            'admin.users.web_admins.manage',
            'admin.users.accountants.view',
            'admin.users.accountants.manage',
            'admin.advance_accounting.view',
            'admin.advance_accounting.manage',
        ];

        $webAdminPermissions = [
            'admin.access',
            'admin.users.foremen.view',
            'admin.users.foremen.manage', 
            'admin.users.web_admins.view', // Can view other web_admins
            'admin.users.web_admins.manage', // Decide if web_admins can manage other web_admins
            'admin.users.accountants.view',
            'admin.advance_accounting.view', // View only for finance
        ];

        $accountantPermissions = [
            'admin.access',
            'admin.users.accountants.view', // Can view other accountants
            'admin.advance_accounting.view',
            'admin.advance_accounting.manage',
        ];
        
        $rolePermissionsMap = [
            'super_admin' => $adminPanelPermissions,
            'admin' => $adminPermissions,
            'content_admin' => [],
            'support_admin' => [],
            Role::ROLE_SYSTEM_ADMIN => $systemAdminPermissions,
            Role::ROLE_OWNER => $ownerPermissions,
            Role::ROLE_ADMIN => $adminPermissions,
            'web_admin' => $webAdminPermissions,
            'accountant' => $accountantPermissions,
            Role::ROLE_FOREMAN => [], // Foreman has no admin panel permissions
        ];

        foreach ($rolePermissionsMap as $roleSlug => $permissionSlugs) {
            $role = Role::where('slug', $roleSlug)->first();
            if ($role) {
                $permissionIds = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->toArray();
                $role->permissions()->sync($permissionIds);
                $this->command->line("Synced permissions for role: {$role->name}");
            } else {
                $this->command->error("Role with slug '{$roleSlug}' not found. Skipping permission sync.");
            }
        }

        $this->command->info('Permissions attached to roles.');
    }
}
