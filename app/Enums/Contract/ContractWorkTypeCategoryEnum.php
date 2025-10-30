<?php

namespace App\Enums\Contract;

enum ContractWorkTypeCategoryEnum: string
{
    case SMR = 'smr'; // Строительно-монтажные работы
    case GENERAL_CONSTRUCTION = 'general_construction'; // Общестроительные работы
    case FINISHING = 'finishing'; // Отделочные работы
    case INSTALLATION = 'installation'; // Монтажные работы
    case DESIGN = 'design'; // Проектирование
    case CONSULTATION = 'consultation'; // Консультационные услуги
    case SUPPLY = 'supply'; // Поставка
    case SERVICES = 'services'; // Услуги
    case RENT = 'rent'; // Аренда
    case OTHER = 'other'; // Прочие

    public function label(): string
    {
        return __('contract.work_type_category.' . $this->value);
    }
} 