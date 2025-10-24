<?php

return [
    'contract_number' => [
        'label' => 'Номер контракта',
        'type' => 'string',
        'required' => true
    ],
    'contract_date' => [
        'label' => 'Дата контракта',
        'type' => 'date'
    ],
    'status' => [
        'label' => 'Статус',
        'type' => 'string'
    ],
    'total_amount' => [
        'label' => 'Сумма контракта',
        'type' => 'money'
    ],
    'completed_amount' => [
        'label' => 'Выполнено работ',
        'type' => 'money'
    ],
    'payment_amount' => [
        'label' => 'Оплачено',
        'type' => 'money'
    ],
    'remaining_amount' => [
        'label' => 'Остаток к доплате',
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

