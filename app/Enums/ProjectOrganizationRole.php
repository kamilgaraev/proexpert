<?php

namespace App\Enums;

use App\Domain\Project\ValueObjects\ProjectRoleConfig;

enum ProjectOrganizationRole: string
{
    case OWNER = 'owner';
    case CUSTOMER = 'customer';
    case GENERAL_CONTRACTOR = 'general_contractor';
    case CONTRACTOR = 'contractor';
    case SUBCONTRACTOR = 'subcontractor';
    case CONSTRUCTION_SUPERVISION = 'construction_supervision';
    case DESIGNER = 'designer';
    case OBSERVER = 'observer';
    
    public function label(): string
    {
        return match($this) {
            self::OWNER => 'Владелец проекта',
            self::CUSTOMER => 'Заказчик',
            self::GENERAL_CONTRACTOR => 'Генподрядчик',
            self::CONTRACTOR => 'Подрядчик',
            self::SUBCONTRACTOR => 'Субподрядчик',
            self::CONSTRUCTION_SUPERVISION => 'Стройконтроль',
            self::DESIGNER => 'Проектировщик',
            self::OBSERVER => 'Наблюдатель',
        };
    }
    
    /**
     * Получить конфигурацию роли
     */
    public function config(): ProjectRoleConfig
    {
        return match($this) {
            self::OWNER => new ProjectRoleConfig(
                permissions: ['*'], // Полный доступ
                ui_behavior: [
                    'contractor_field' => 'visible_required',
                    'can_create_works' => true,
                    'can_approve_works' => true,
                    'can_assign_contractors' => true,
                    'view_scope' => 'all',
                ],
                description: 'Владелец проекта имеет полный доступ ко всем функциям',
            ),
            
            self::CUSTOMER => new ProjectRoleConfig(
                permissions: [
                    'projects.view',
                    'contracts.view_all',
                    'works.view_all',
                    'works.approve',
                    'reports.view',
                    'reports.create',
                    'finance.view',
                ],
                ui_behavior: [
                    'contractor_field' => 'visible_readonly',
                    'can_create_works' => false,
                    'can_approve_works' => true,
                    'view_scope' => 'all',
                    'modules_hidden' => ['basic-warehouse', 'advanced-warehouse'],
                ],
                description: 'Заказчик может просматривать всё, утверждать работы, но не создавать',
            ),
            
            self::GENERAL_CONTRACTOR => new ProjectRoleConfig(
                permissions: [
                    'projects.*',
                    'contracts.*',
                    'works.*',
                    'materials.*',
                    'contractors.assign',
                    'reports.*',
                ],
                ui_behavior: [
                    'contractor_field' => 'visible_required',
                    'can_create_works' => true,
                    'can_approve_works' => true,
                    'can_assign_contractors' => true,
                    'view_scope' => 'all',
                ],
                description: 'Генподрядчик управляет проектом, назначает подрядчиков',
            ),
            
            self::CONTRACTOR => new ProjectRoleConfig(
                permissions: [
                    'projects.view',
                    'contracts.view_own',
                    'works.create_own',
                    'works.view_own',
                    'materials.request',
                    'materials.view_own',
                ],
                ui_behavior: [
                    'contractor_field' => 'hidden_autofill',
                    'can_create_works' => true,
                    'can_approve_works' => false,
                    'view_scope' => 'own',
                ],
                description: 'Подрядчик создает работы только для себя, видит только свои данные',
            ),
            
            self::SUBCONTRACTOR => new ProjectRoleConfig(
                permissions: [
                    'projects.view',
                    'contracts.view_own',
                    'works.create_own',
                    'works.view_own',
                    'materials.view_assigned',
                ],
                ui_behavior: [
                    'contractor_field' => 'hidden_autofill',
                    'can_create_works' => true,
                    'can_approve_works' => false,
                    'view_scope' => 'own',
                    'limited_scope' => true,
                ],
                description: 'Субподрядчик выполняет узкоспециализированные работы',
            ),
            
            self::DESIGNER => new ProjectRoleConfig(
                permissions: [
                    'projects.view',
                    'documents.manage',
                    'drawings.edit',
                    'specifications.create',
                ],
                ui_behavior: [
                    'contractor_field' => 'hidden',
                    'can_create_works' => false,
                    'can_approve_works' => false,
                    'modules_visible' => ['project-management', 'workflow-management'],
                    'modules_hidden' => ['basic-warehouse', 'contract-management'],
                ],
                description: 'Проектировщик работает с документацией и чертежами',
            ),
            
            self::CONSTRUCTION_SUPERVISION => new ProjectRoleConfig(
                permissions: [
                    'projects.view',
                    'works.view_all',
                    'works.approve',
                    'reports.verify',
                    'quality.control',
                ],
                ui_behavior: [
                    'contractor_field' => 'visible_readonly',
                    'can_create_works' => false,
                    'can_approve_works' => true,
                    'quality_module' => true,
                    'view_scope' => 'all',
                ],
                description: 'Стройконтроль осуществляет технический надзор',
            ),
            
            self::OBSERVER => new ProjectRoleConfig(
                permissions: [
                    '*.view', // Только просмотр
                ],
                ui_behavior: [
                    'contractor_field' => 'visible_readonly',
                    'can_create_works' => false,
                    'can_approve_works' => false,
                    'read_only' => true,
                    'view_scope' => 'all',
                ],
                description: 'Наблюдатель имеет доступ только на чтение',
            ),
        };
    }
    
    /**
     * Все роли как массив для forms
     */
    public static function toArray(): array
    {
        return array_map(
            fn($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->config()->description,
            ],
            self::cases()
        );
    }
}

