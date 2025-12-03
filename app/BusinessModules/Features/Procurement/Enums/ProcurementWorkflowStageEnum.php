<?php

namespace App\BusinessModules\Features\Procurement\Enums;

enum ProcurementWorkflowStageEnum: string
{
    case REQUEST_CREATED = 'request_created';
    case REQUEST_APPROVED = 'request_approved';
    case ORDER_CREATED = 'order_created';
    case ORDER_SENT = 'order_sent';
    case PROPOSAL_RECEIVED = 'proposal_received';
    case PROPOSAL_ACCEPTED = 'proposal_accepted';
    case CONTRACT_CREATED = 'contract_created';
    case INVOICE_CREATED = 'invoice_created';
    case MATERIAL_RECEIVED = 'material_received';

    public function label(): string
    {
        return match ($this) {
            self::REQUEST_CREATED => 'Создана заявка на закупку',
            self::REQUEST_APPROVED => 'Заявка одобрена',
            self::ORDER_CREATED => 'Создан заказ поставщику',
            self::ORDER_SENT => 'Заказ отправлен поставщику',
            self::PROPOSAL_RECEIVED => 'Получено КП от поставщика',
            self::PROPOSAL_ACCEPTED => 'КП принято',
            self::CONTRACT_CREATED => 'Создан договор поставки',
            self::INVOICE_CREATED => 'Создан счет на оплату',
            self::MATERIAL_RECEIVED => 'Материал получен на склад',
        };
    }
}

