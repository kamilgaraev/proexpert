<?php

namespace App\Enums\Contract;

enum ContractPaymentTypeEnum: string
{
    case ADVANCE = 'advance'; // Аванс
    case FACT_PAYMENT = 'fact_payment'; // Оплата по факту (например, по КС)
    case DEFERRED_PAYMENT = 'deferred_payment'; // Отложенный платеж
    case OTHER = 'other'; // Другой тип платежа
} 