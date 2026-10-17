<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;

final class PaymentRequestOrderQuery
{
    public static function matchingOrderSql(
        string $requestAlias = 'payment_documents',
        string $orderAlias = 'linked_order',
        string $requestActAlias = 'contract_performance_acts',
        string $orderActAlias = 'linked_order_act',
    ): string {
        $documentClass = str_replace("'", "''", PaymentDocument::class);
        $orderType = PaymentDocumentType::PAYMENT_ORDER->value;
        $dimensions = implode(' AND ', array_map(
            static fn (string $field): string => "{$orderAlias}.{$field} IS NOT DISTINCT FROM {$requestAlias}.{$field}",
            ['project_id', 'payer_organization_id', 'payer_contractor_id', 'payee_organization_id', 'payee_contractor_id',
                'contractor_id', 'counterparty_organization_id'],
        ));
        $requestContract = self::contractIdentitySql($requestAlias, $requestActAlias);
        $orderContract = self::contractIdentitySql($orderAlias, $orderActAlias);

        return "{$orderAlias}.organization_id = {$requestAlias}.organization_id "
            ."AND {$orderAlias}.deleted_at IS NULL "
            ."AND {$orderAlias}.document_type = '{$orderType}' AND {$orderAlias}.source_type = '{$documentClass}' "
            ."AND {$orderAlias}.source_id = {$requestAlias}.id "
            ."AND {$orderAlias}.currency = {$requestAlias}.currency "
            ."AND COALESCE({$orderAlias}.direction, 'outgoing') = COALESCE({$requestAlias}.direction, 'outgoing') "
            ."AND {$dimensions} "
            ."AND (({$requestContract}) IS NOT NULL AND ({$requestContract}) = ({$orderContract}) "
            ."OR {$orderAlias}.invoiceable_type IS NOT DISTINCT FROM {$requestAlias}.invoiceable_type "
            ."AND {$orderAlias}.invoiceable_id IS NOT DISTINCT FROM {$requestAlias}.invoiceable_id)";
    }

    public static function contractIdentitySql(
        string $documentAlias = 'payment_documents',
        string $actAlias = 'contract_performance_acts',
    ): string {
        $contractClass = str_replace("'", "''", Contract::class);

        return "CASE WHEN {$documentAlias}.invoiceable_type = '{$contractClass}' "
            ."THEN {$documentAlias}.invoiceable_id ELSE {$actAlias}.contract_id END";
    }
}
