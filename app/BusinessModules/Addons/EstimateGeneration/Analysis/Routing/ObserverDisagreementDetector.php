<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;

final readonly class ObserverDisagreementDetector
{
    /** @param array<string, AiRoleRunResult> $observerResults */
    public function hasMaterialDisagreement(array $observerResults): bool
    {
        $values = [];
        foreach ($observerResults as $result) {
            $claims = is_array($result->payload['claims'] ?? null) ? $result->payload['claims'] : [];
            foreach ($claims as $claim) {
                if (! is_array($claim)
                    || ! is_string($claim['entityKey'] ?? null)
                    || ! is_string($claim['factType'] ?? null)
                    || ! array_key_exists('value', $claim)) {
                    continue;
                }
                $identity = mb_strtolower(trim($claim['entityKey']))."\0".mb_strtolower(trim($claim['factType']));
                $value = $claim['value'];
                if (is_array($value) && ! array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }
                $values[$identity][hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))] = true;
                if (count($values[$identity]) > 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
