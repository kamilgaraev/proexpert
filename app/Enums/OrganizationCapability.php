<?php

namespace App\Enums;

enum OrganizationCapability: string
{
    case GENERAL_CONTRACTING = 'general_contracting';
    case SUBCONTRACTING = 'subcontracting';
    case DESIGN = 'design';
    case CONSTRUCTION_SUPERVISION = 'construction_supervision';
    case EQUIPMENT_RENTAL = 'equipment_rental';
    case MATERIALS_SUPPLY = 'materials_supply';
    case CONSULTING = 'consulting';
    case FACILITY_MANAGEMENT = 'facility_management';
    
    public function label(): string
    {
        return match($this) {
            self::GENERAL_CONTRACTING => 'Генеральный подряд',
            self::SUBCONTRACTING => 'Субподрядные работы',
            self::DESIGN => 'Проектирование',
            self::CONSTRUCTION_SUPERVISION => 'Строительный контроль',
            self::EQUIPMENT_RENTAL => 'Аренда техники',
            self::MATERIALS_SUPPLY => 'Поставка материалов',
            self::CONSULTING => 'Консалтинг',
            self::FACILITY_MANAGEMENT => 'Эксплуатация объектов',
        };
    }
    
    public function description(): string
    {
        return match($this) {
            self::GENERAL_CONTRACTING => 'Организация выступает генеральным подрядчиком, управляет всеми работами на объекте',
            self::SUBCONTRACTING => 'Организация выполняет отдельные виды работ по субподрядным договорам',
            self::DESIGN => 'Организация разрабатывает проектную и рабочую документацию',
            self::CONSTRUCTION_SUPERVISION => 'Организация осуществляет технический надзор за строительством',
            self::EQUIPMENT_RENTAL => 'Организация предоставляет в аренду строительную технику и оборудование',
            self::MATERIALS_SUPPLY => 'Организация поставляет строительные материалы',
            self::CONSULTING => 'Организация оказывает консультационные услуги',
            self::FACILITY_MANAGEMENT => 'Организация обслуживает готовые объекты',
        };
    }
    
    /**
     * Какие модули рекомендуются для этой capability
     */
    public function recommendedModules(): array
    {
        return match($this) {
            self::GENERAL_CONTRACTING => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'schedule-management',
                'advanced-dashboard',
                'time-tracking',
            ],
            self::SUBCONTRACTING => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'time-tracking',
            ],
            self::DESIGN => [
                'project-management',
                'workflow-management',
            ],
            self::CONSTRUCTION_SUPERVISION => [
                'project-management',
                'workflow-management',
            ],
            self::EQUIPMENT_RENTAL => [
                'project-management',
                'contract-management',
            ],
            self::MATERIALS_SUPPLY => [
                'project-management',
                'contract-management',
                'basic-warehouse',
                'catalog-management',
            ],
            self::CONSULTING => [
                'project-management',
            ],
            self::FACILITY_MANAGEMENT => [
                'project-management',
                'schedule-management',
            ],
        };
    }
    
    /**
     * Какие роли в проекте поддерживает эта capability
     */
    public function supportsProjectRole(ProjectOrganizationRole $role): bool
    {
        return match($this) {
            self::GENERAL_CONTRACTING => in_array($role, [
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
                ProjectOrganizationRole::CUSTOMER,
            ]),
            self::SUBCONTRACTING => in_array($role, [
                ProjectOrganizationRole::CONTRACTOR,
                ProjectOrganizationRole::SUBCONTRACTOR,
            ]),
            self::DESIGN => in_array($role, [
                ProjectOrganizationRole::DESIGNER,
            ]),
            self::CONSTRUCTION_SUPERVISION => in_array($role, [
                ProjectOrganizationRole::CONSTRUCTION_SUPERVISION,
            ]),
            default => true, // Остальные capabilities поддерживают любые роли
        };
    }
    
    /**
     * Все доступные capabilities как массив для forms
     */
    public static function toArray(): array
    {
        return array_map(
            fn($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'recommended_modules' => $case->recommendedModules(),
            ],
            self::cases()
        );
    }
}

