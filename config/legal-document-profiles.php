<?php

declare(strict_types=1);

return [
    'contract.work' => ['label' => 'Договор подряда', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.construction' => ['label' => 'Договор строительного подряда', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.subcontract' => ['label' => 'Договор субподряда', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.supply' => [
        'label' => 'Договор поставки',
        'category' => 'contract',
        'required_file_roles' => ['primary'],
        'required_fields' => ['subject', 'buyer', 'supplier', 'price', 'delivery_terms'],
        'schema' => [
            'subject' => ['type' => 'string', 'label' => 'Предмет договора'],
            'buyer' => ['type' => 'string', 'label' => 'Покупатель'],
            'supplier' => ['type' => 'string', 'label' => 'Поставщик'],
            'price' => ['type' => 'number', 'label' => 'Цена'],
            'delivery_terms' => ['type' => 'string', 'label' => 'Условия поставки'],
        ],
        'requires_signature' => true,
    ],
    'contract.services' => ['label' => 'Договор оказания услуг', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.design-survey' => ['label' => 'Договор на проектные и изыскательские работы', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.lease' => ['label' => 'Договор аренды', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.carriage' => ['label' => 'Договор перевозки', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.agency' => ['label' => 'Агентский договор', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.license' => ['label' => 'Лицензионный договор', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.confidentiality' => ['label' => 'Соглашение о конфиденциальности', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true, 'confidentiality_level' => 'restricted'],
    'contract.framework' => ['label' => 'Рамочный договор', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'contract.mixed' => ['label' => 'Смешанный договор', 'category' => 'contract', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],

    'amendment.supplementary-agreement' => ['label' => 'Дополнительное соглашение', 'category' => 'amendment', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'amendment.specification' => ['label' => 'Спецификация', 'category' => 'amendment', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'amendment.appendix' => ['label' => 'Приложение', 'category' => 'amendment', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'amendment.disagreement-protocol' => ['label' => 'Протокол разногласий', 'category' => 'amendment', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],

    'execution.act' => ['label' => 'Акт', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'execution.upd' => ['label' => 'Универсальный передаточный документ', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'execution.waybill' => ['label' => 'Накладная', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'execution.invoice' => ['label' => 'Счёт', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'execution.vat-invoice' => ['label' => 'Счёт-фактура', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'execution.reconciliation-statement' => ['label' => 'Акт сверки', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'execution.enforcement-document' => ['label' => 'Исполнительный документ', 'category' => 'execution', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],

    'procurement.request' => ['label' => 'Заявка на закупку', 'category' => 'procurement', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'procurement.request-for-proposal' => ['label' => 'Запрос предложения', 'category' => 'procurement', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'procurement.supplier-proposal' => ['label' => 'Предложение поставщика', 'category' => 'procurement', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'procurement.purchase-order' => ['label' => 'Заказ поставщику', 'category' => 'procurement', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'procurement.tender-document' => ['label' => 'Тендерный документ', 'category' => 'procurement', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],

    'corporate.power-of-attorney' => ['label' => 'Доверенность', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'corporate.order' => ['label' => 'Приказ', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'corporate.decision' => ['label' => 'Решение', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'corporate.minutes' => ['label' => 'Протокол', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'corporate.regulation' => ['label' => 'Положение', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'corporate.license' => ['label' => 'Лицензия', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'corporate.certificate' => ['label' => 'Сертификат', 'category' => 'corporate', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],

    'claim.claim' => ['label' => 'Претензия', 'category' => 'claim', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'claim.response' => ['label' => 'Ответ на претензию', 'category' => 'claim', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'claim.demand' => ['label' => 'Требование', 'category' => 'claim', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'claim.notice' => ['label' => 'Уведомление', 'category' => 'claim', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'claim.court-case-document' => ['label' => 'Документ судебного дела', 'category' => 'claim', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false, 'confidentiality_level' => 'restricted'],

    'correspondence.incoming-letter' => ['label' => 'Входящее письмо', 'category' => 'correspondence', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
    'correspondence.outgoing-letter' => ['label' => 'Исходящее письмо', 'category' => 'correspondence', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => true],
    'correspondence.internal-memo' => ['label' => 'Служебная записка', 'category' => 'correspondence', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],

    'other.custom' => ['label' => 'Пользовательский юридический документ', 'category' => 'other', 'required_file_roles' => ['primary'], 'required_fields' => [], 'schema' => [], 'requires_signature' => false],
];
