<?php

declare(strict_types=1);

namespace App\Support\Organization;

use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;
use App\Helpers\ModuleHelper;

final class OrganizationWorkspaceProfileCatalog
{
    public const INTERACTION_PROJECT_PARTICIPANT = 'project_participant';
    public const INTERACTION_PROCUREMENT_COUNTERPARTY = 'procurement_counterparty';
    public const INTERACTION_SERVICE_COUNTERPARTY = 'service_counterparty';

    private const PROFILES = [
        OrganizationCapability::GENERAL_CONTRACTING->value => [
            'default_route' => '/dashboard/projects',
            'interaction_modes' => [
                self::INTERACTION_PROJECT_PARTICIPANT,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
                ProjectOrganizationRole::CONTRACTOR->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'schedule-management',
                'time-tracking',
            ],
            'recommended_actions' => [
                [
                    'key' => 'create_project',
                    'label' => 'Создать проект',
                    'route' => '/dashboard/projects/create',
                ],
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_modules',
                    'label' => 'Проверить модули',
                    'route' => '/dashboard/modules',
                ],
            ],
        ],
        OrganizationCapability::SUBCONTRACTING->value => [
            'default_route' => '/dashboard/projects',
            'interaction_modes' => [
                self::INTERACTION_PROJECT_PARTICIPANT,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::CONTRACTOR->value,
                ProjectOrganizationRole::SUBCONTRACTOR->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'time-tracking',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_invitations',
                    'label' => 'Проверить приглашения',
                    'route' => '/dashboard/contractor-invitations',
                ],
                [
                    'key' => 'open_modules',
                    'label' => 'Проверить модули',
                    'route' => '/dashboard/modules',
                ],
            ],
        ],
        OrganizationCapability::DESIGN->value => [
            'default_route' => '/dashboard/projects',
            'interaction_modes' => [
                self::INTERACTION_PROJECT_PARTICIPANT,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::DESIGNER->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'workflow-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_settings',
                    'label' => 'Настроить профиль',
                    'route' => '/dashboard/organization/settings',
                ],
            ],
        ],
        OrganizationCapability::CONSTRUCTION_SUPERVISION->value => [
            'default_route' => '/dashboard/projects',
            'interaction_modes' => [
                self::INTERACTION_PROJECT_PARTICIPANT,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::CONSTRUCTION_SUPERVISION->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'workflow-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_settings',
                    'label' => 'Настроить профиль',
                    'route' => '/dashboard/organization/settings',
                ],
            ],
        ],
        OrganizationCapability::EQUIPMENT_RENTAL->value => [
            'default_route' => '/dashboard/modules',
            'interaction_modes' => [
                self::INTERACTION_SERVICE_COUNTERPARTY,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'contract-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_modules',
                    'label' => 'Проверить модули',
                    'route' => '/dashboard/modules',
                ],
                [
                    'key' => 'open_settings',
                    'label' => 'Настроить профиль',
                    'route' => '/dashboard/organization/settings',
                ],
            ],
        ],
        OrganizationCapability::MATERIALS_SUPPLY->value => [
            'default_route' => '/dashboard/modules',
            'interaction_modes' => [
                self::INTERACTION_PROCUREMENT_COUNTERPARTY,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'catalog-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_modules',
                    'label' => 'Проверить модули',
                    'route' => '/dashboard/modules',
                ],
                [
                    'key' => 'open_settings',
                    'label' => 'Настроить профиль',
                    'route' => '/dashboard/organization/settings',
                ],
            ],
        ],
        OrganizationCapability::CONSULTING->value => [
            'default_route' => '/dashboard/organization/settings',
            'interaction_modes' => [
                self::INTERACTION_SERVICE_COUNTERPARTY,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_settings',
                    'label' => 'Настроить профиль',
                    'route' => '/dashboard/organization/settings',
                ],
            ],
        ],
        OrganizationCapability::FACILITY_MANAGEMENT->value => [
            'default_route' => '/dashboard/projects',
            'interaction_modes' => [
                self::INTERACTION_PROJECT_PARTICIPANT,
                self::INTERACTION_SERVICE_COUNTERPARTY,
            ],
            'allowed_project_roles' => [
                ProjectOrganizationRole::CUSTOMER->value,
                ProjectOrganizationRole::OBSERVER->value,
            ],
            'recommended_modules' => [
                'project-management',
                'schedule-management',
            ],
            'recommended_actions' => [
                [
                    'key' => 'open_projects',
                    'label' => 'Открыть проекты',
                    'route' => '/dashboard/projects',
                ],
                [
                    'key' => 'open_modules',
                    'label' => 'Проверить модули',
                    'route' => '/dashboard/modules',
                ],
            ],
        ],
    ];

    public static function buildWorkspaceProfile(array $capabilityValues, ?string $primaryBusinessType): array
    {
        $resolvedPrimary = self::resolvePrimaryProfile($capabilityValues, $primaryBusinessType);

        return [
            'primary_profile' => $resolvedPrimary?->value,
            'workspace_options' => self::workspaceOptions($capabilityValues),
            'recommended_actions' => self::recommendedActions($capabilityValues, $resolvedPrimary?->value),
        ];
    }

    public static function resolvePrimaryProfile(array $capabilityValues, ?string $primaryBusinessType): ?OrganizationCapability
    {
        $normalizedCapabilities = self::normalizeCapabilityValues($capabilityValues);
        if ($normalizedCapabilities === []) {
            return null;
        }

        if ($primaryBusinessType !== null && in_array($primaryBusinessType, $normalizedCapabilities, true)) {
            return OrganizationCapability::tryFrom($primaryBusinessType);
        }

        if (count($normalizedCapabilities) === 1) {
            return OrganizationCapability::tryFrom($normalizedCapabilities[0]);
        }

        return null;
    }

    public static function normalizePrimaryProfile(array $capabilityValues, ?string $primaryBusinessType): ?string
    {
        return self::resolvePrimaryProfile($capabilityValues, $primaryBusinessType)?->value;
    }

    public static function workspaceOptions(array $capabilityValues): array
    {
        return array_values(array_filter(array_map(function (string $capabilityValue): ?array {
            $capability = OrganizationCapability::tryFrom($capabilityValue);
            $definition = self::PROFILES[$capabilityValue] ?? null;

            if ($capability === null || $definition === null) {
                return null;
            }

            return [
                'value' => $capability->value,
                'label' => $capability->label(),
                'default_route' => $definition['default_route'],
                'interaction_modes' => $definition['interaction_modes'],
                'allowed_project_roles' => $definition['allowed_project_roles'],
                'recommended_modules' => ModuleHelper::formatModules($definition['recommended_modules']),
            ];
        }, self::normalizeCapabilityValues($capabilityValues))));
    }

    public static function recommendedModules(array $capabilityValues, ?string $primaryBusinessType): array
    {
        $orderedCapabilities = self::orderCapabilities($capabilityValues, $primaryBusinessType);
        $moduleSlugs = [];

        foreach ($orderedCapabilities as $capabilityValue) {
            $definition = self::PROFILES[$capabilityValue] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition['recommended_modules'] as $moduleSlug) {
                if (!in_array($moduleSlug, $moduleSlugs, true)) {
                    $moduleSlugs[] = $moduleSlug;
                }
            }
        }

        return ModuleHelper::formatModules($moduleSlugs);
    }

    public static function allowedProjectRoles(array $capabilityValues): array
    {
        $roles = [];

        foreach (self::workspaceOptions($capabilityValues) as $workspaceOption) {
            foreach ($workspaceOption['allowed_project_roles'] as $role) {
                if (!in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        }

        if (!in_array(ProjectOrganizationRole::OBSERVER->value, $roles, true)) {
            $roles[] = ProjectOrganizationRole::OBSERVER->value;
        }

        return $roles;
    }

    public static function recommendedActions(array $capabilityValues, ?string $primaryBusinessType): array
    {
        $orderedCapabilities = self::orderCapabilities($capabilityValues, $primaryBusinessType);
        $actions = [];

        foreach ($orderedCapabilities as $capabilityValue) {
            $definition = self::PROFILES[$capabilityValue] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition['recommended_actions'] as $action) {
                $actions[$action['key']] = $action;
            }
        }

        return array_values($actions);
    }

    private static function orderCapabilities(array $capabilityValues, ?string $primaryBusinessType): array
    {
        $normalizedCapabilities = self::normalizeCapabilityValues($capabilityValues);
        $primaryProfile = self::resolvePrimaryProfile($normalizedCapabilities, $primaryBusinessType);

        if ($primaryProfile === null) {
            return $normalizedCapabilities;
        }

        return array_values(array_unique([
            $primaryProfile->value,
            ...$normalizedCapabilities,
        ]));
    }

    private static function normalizeCapabilityValues(array $capabilityValues): array
    {
        $normalized = [];

        foreach ($capabilityValues as $capabilityValue) {
            if (!is_string($capabilityValue)) {
                continue;
            }

            if (OrganizationCapability::tryFrom($capabilityValue) === null) {
                continue;
            }

            if (!in_array($capabilityValue, $normalized, true)) {
                $normalized[] = $capabilityValue;
            }
        }

        return $normalized;
    }
}
