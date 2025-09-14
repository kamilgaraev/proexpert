<?php

namespace App\Services;

use App\Helpers\PermissionTranslator;

class PermissionTranslationService
{
    public function processPermissionsForFrontend(array $permissionsData): array
    {
        return PermissionTranslator::translatePermissionsData($permissionsData);
    }

    public function getSystemPermissionsWithTranslations(): array
    {
        $systemPermissions = [
            'profile.view',
            'profile.edit',
            'organization.view',
            'organization.edit',
            'users.view',
            'users.invite',
            'users.manage',
            'users.manage_admin',
            'users.assign_roles',
            'roles.view_custom',
            'roles.create_custom',
            'roles.manage_custom',
            'modules.manage',
            'admin.access',
        ];

        $translations = [];
        foreach ($systemPermissions as $permission) {
            $translations[$permission] = PermissionTranslator::getPermissionTranslation($permission);
        }

        return $translations;
    }

    public function getModulePermissionsWithTranslations(): array
    {
        $modulePermissions = [
            'basic-reports' => [
                'basic_reports.view',
                'basic_reports.material_usage',
                'basic_reports.work_completion',
                'basic_reports.project_summary',
                'basic_reports.export_excel',
                'basic_reports.export_pdf'
            ],
            'users' => [
                'users.view',
                'users.create',
                'users.edit',
                'users.delete',
                'users.roles',
                'users.permissions'
            ],
            'organizations' => [
                'organizations.view',
                'organizations.create',
                'organizations.edit',
                'organizations.delete',
                'organizations.settings'
            ]
        ];

        return PermissionTranslator::translateModulePermissions($modulePermissions);
    }

    public function getInterfaceAccessWithTranslations(): array
    {
        $interfaces = [
            'lk' => 'Личный кабинет',
            'mobile' => 'Мобильное приложение',
            'admin' => 'Административная панель'
        ];

        return PermissionTranslator::translateInterfaceAccess($interfaces);
    }
}
