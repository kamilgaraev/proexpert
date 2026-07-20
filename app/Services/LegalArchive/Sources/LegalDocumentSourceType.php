<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

enum LegalDocumentSourceType: string
{
    case PROJECT = 'project';
    case CONTRACT = 'contract';
    case SUPPLEMENTARY_AGREEMENT = 'supplementary_agreement';
    case PERFORMANCE_ACT = 'performance_act';
    case PURCHASE_ORDER = 'purchase_order';
    case PAYMENT_DOCUMENT = 'payment_document';
    case COMMERCIAL_PROPOSAL = 'commercial_proposal';
    case CRM_DEAL = 'crm_deal';
    case ESTIMATE = 'estimate';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
