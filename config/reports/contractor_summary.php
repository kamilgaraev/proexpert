<?php

return [
    'contractor_name' => [
        'label' => 'Название подрядчика',
        'type' => 'string',
        'required' => true
    ],
    'contact_person' => [
        'label' => 'Контактное лицо',
        'type' => 'string'
    ],
    'phone' => [
        'label' => 'Телефон',
        'type' => 'string'
    ],
    'email' => [
        'label' => 'Email',
        'type' => 'string'
    ],
    'contractor_type' => [
        'label' => 'Тип подрядчика',
        'type' => 'string'
    ],
    'contracts_count' => [
        'label' => 'Количество контрактов',
        'type' => 'integer'
    ],
    'total_contract_amount' => [
        'label' => 'Сумма контрактов',
        'type' => 'money'
    ],
    'total_completed_amount' => [
        'label' => 'Выполнено работ',
        'type' => 'money'
    ],
    'total_payment_amount' => [
        'label' => 'Оплачено',
        'type' => 'money'
    ],
    'remaining_to_complete' => [
        'label' => 'Остаток к выполнению',
        'type' => 'money'
    ],
    'remaining_amount' => [
        'label' => 'Остаток к оплате',
        'type' => 'money'
    ],
    'completion_percentage' => [
        'label' => 'Процент выполнения',
        'type' => 'percentage'
    ],
    'payment_percentage' => [
        'label' => 'Процент оплаты',
        'type' => 'percentage'
    ],
];

