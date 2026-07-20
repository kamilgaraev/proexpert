<?php

declare(strict_types=1);

return [
    'max_offer_age_days' => 45,
    'analyses' => [
        'residential_led_ceiling_luminaire_18w' => [
            'resource_code' => '59.1.20.03-0798',
            'resource_name' => 'Светильник светодиодный потолочный накладной, 18 Вт, IP20',
            'unit' => 'шт',
            'required_name_markers' => ['светильник', 'светодиод', '18'],
            'forbidden_name_markers' => ['офис', 'склад', 'промышлен', 'общественн'],
            'offers' => [
                [
                    'supplier' => 'Мир светодиодов',
                    'url' => 'https://kazan.mir-svetodiodov.ru/svetodiodnie-svetilniki/nakladnye/led-18w-1400lm-6000k-29061',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Накладной светодиодный светильник LED Surface 18W 1400lm 6000K d225 мм 29061',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 2250.00,
                ],
                [
                    'supplier' => 'ВсеИнструменты.ру',
                    'url' => 'https://www.vseinstrumenti.ru/product/svetilnik-svetodiodnyj-nakladnoj-apeyron-min-18vt-1620lm-4000k-ip20-o170x35mm-belyj-18-155-19610604/',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Светильник светодиодный накладной Apeyron MIN 18-155, 18 Вт, IP20',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 864.00,
                ],
                [
                    'supplier' => 'Совок',
                    'url' => 'https://sovok.ru/kazan/s-svetodiodnie-svetilniki-potolochnie-nakladnie-16175.html',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Светильник накладной светодиодный 18W 4000K звездное небо AL589',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 1260.00,
                ],
            ],
        ],
        'residential_wall_mounted_single_circuit_electric_boiler_18kw' => [
            'resource_code' => '89.1.63.01-0079',
            'resource_name' => 'Котёл электрический настенный одноконтурный, 18 кВт',
            'unit' => 'шт',
            'required_name_markers' => ['кот', 'электр', '18'],
            'forbidden_name_markers' => ['газов', 'пищевар', 'офис', 'склад', 'промышлен'],
            'offers' => [
                [
                    'supplier' => 'SUPER ГАЗ',
                    'url' => 'https://kazan.supergas.ru/kotly/kotly-elektricheskie/electrovel/elektrokotel-electrovel/elektrokotel-electrovel-18-kvt-380',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Электрокотёл ElectroVel ЭВПМ 18 кВт 380 В',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 10710.00,
                ],
                [
                    'supplier' => 'Мистер Климат',
                    'url' => 'https://kazan.mrklimat.ru/elektrokotly',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Электрокотёл Rilano ЭВПМ-18, 18 кВт, 380 В',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 20400.00,
                ],
                [
                    'supplier' => 'Тепложар',
                    'url' => 'https://kaz.teplozhar.ru/kotel-otopleniya/elektricheskiy/380-v/',
                    'region_code' => 'RU-TA',
                    'observed_at' => '2026-07-20',
                    'product_name' => 'Электрокотёл СТЭН ЭВПМ 3–18 кВт, исполнение 18 кВт',
                    'unit' => 'шт',
                    'currency' => 'RUB',
                    'price' => 18810.00,
                ],
            ],
        ],
    ],
];
