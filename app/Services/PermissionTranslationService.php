<?php

namespace App\Services;

use App\Helpers\PermissionTranslator;
use App\Services\Logging\LoggingService;

class PermissionTranslationService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }
    public function processPermissionsForFrontend(array $permissionsData): array
    {
        $startTime = microtime(true);
        
        $this->logging->technical('permission_translation.frontend.started', [
            'permissions_count' => count($permissionsData)
        ]);

        try {
            $result = PermissionTranslator::translatePermissionsData($permissionsData);
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('permission_translation.frontend.completed', [
                'permissions_count' => count($permissionsData),
                'translated_count' => count($result),
                'duration_ms' => $duration
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('permission_translation.frontend.failed', [
                'permissions_count' => count($permissionsData),
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }

    public function getSystemPermissionsWithTranslations(): array
    {
        $this->logging->technical('permission_translation.system.requested');
        
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

        $this->logging->technical('permission_translation.system.completed', [
            'system_permissions_count' => count($systemPermissions),
            'translated_count' => count($translations)
        ]);

        return $translations;
    }

    public function getModulePermissionsWithTranslations(): array
    {
        $modulePermissions = [
            'reports' => [
                'reports.view',
                'reports.export',
                'reports.manage_templates',
                'reports.custom_reports',
                'reports.share',
                'reports.schedule',
                'reports.official_reports',
                'reports.predictive'
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
