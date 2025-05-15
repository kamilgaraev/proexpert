<?php

namespace App\Enums\Contract;

enum ContractWorkTypeCategoryEnum: string
{
    case SMR = 'smr'; // Строительно-монтажные работы
    case SUPPLY = 'supply'; // Поставка
    case SERVICES = 'services'; // Услуги
    case RENT = 'rent'; // Аренда
    case OTHER = 'other'; // Прочие
} 