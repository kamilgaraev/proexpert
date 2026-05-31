<?php

declare(strict_types=1);

return [
    'validation' => [
        'name_required' => 'Укажите название материала.',
        'name_unique' => 'Материал с таким названием уже есть в вашей организации.',
        'measurement_unit_required' => 'Укажите единицу измерения.',
        'measurement_unit_exists' => 'Выбранная единица измерения недоступна.',
        'external_code_unique' => 'Материал с таким внешним кодом уже есть в вашей организации.',
        'default_price_min' => 'Цена по умолчанию не может быть отрицательной.',
        'consumption_rate_min' => 'Норма списания не может быть отрицательной.',
    ],
];
