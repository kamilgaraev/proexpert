<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Models\ContractStateEvent;

final class ContractAgreementEventFormatter
{
    public function format(ContractStateEvent $event): ?string
    {
        if (! in_array($event->event_type, [
            ContractStateEventTypeEnum::AMENDED,
            ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED,
        ], true)) {
            return null;
        }

        $metadata = $event->metadata ?? [];
        $number = $metadata['agreement_number'] ?? null;
        if (! is_string($number) || trim($number) === '' || isset($metadata['triggered_by'])) {
            return null;
        }

        $amount = (float) $event->amount_delta;
        $formattedAmount = number_format(abs($amount), 2, ',', ' ').' ₽';
        $signedAmount = ($amount < 0 ? '−' : ($amount > 0 ? '+' : '')).$formattedAmount;

        if ($event->event_type === ContractStateEventTypeEnum::AMENDED && ($metadata['is_compensating'] ?? false) === true) {
            return trans_message('contract_events.agreement_amount_carried', [
                'number' => trim($number),
                'amount' => $amount < 0 ? '−'.$formattedAmount : $formattedAmount,
            ]);
        }

        return trans_message('contract_events.agreement_applied', [
            'number' => trim($number),
            'amount' => $signedAmount,
        ]);
    }
}
