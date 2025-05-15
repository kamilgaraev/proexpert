<?php

namespace App\Enums\Contract;

enum ContractTypeEnum: string
{
    case CONTRACT = 'contract'; // Основной договор
    case AGREEMENT = 'agreement'; // Дополнительное соглашение
    case SPECIFICATION = 'specification'; // Спецификация к договору
} 