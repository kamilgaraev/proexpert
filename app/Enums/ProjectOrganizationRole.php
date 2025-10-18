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
    case PARENT_ADMINISTRATOR = 'parent_administrator';
    
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
            self::PARENT_ADMINISTRATOR => 'Администратор холдинга',
        };
    }
    
    public static function toArray(): array
    {
        return array_map(
            fn($case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases()
        );
    }
}

