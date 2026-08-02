<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO;

use InvalidArgumentException;

final readonly class HandoverGateDefinition
{
    public function __construct(
        public string $code,
        public array $requiredChecklistCodes,
        public array $requiredDocumentCodes,
        public array $hardBlockerSourceTypes,
        public bool $explicitlyEmptyRequirements,
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1
            || ! self::isUniqueIdentifierList($requiredChecklistCodes)
            || ! self::isUniqueIdentifierList($requiredDocumentCodes)
            || ! self::isUniqueIdentifierList($hardBlockerSourceTypes)
            || $explicitlyEmptyRequirements !== (
                $requiredChecklistCodes === [] && $requiredDocumentCodes === []
            )
        ) {
            throw new InvalidArgumentException('handover_gate_definition_invalid');
        }
    }

    private static function isUniqueIdentifierList(array $values): bool
    {
        if (! array_is_list($values)) {
            return false;
        }

        $unique = [];
        foreach ($values as $value) {
            if (
                ! is_string($value)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1
                || isset($unique[$value])
            ) {
                return false;
            }
            $unique[$value] = true;
        }

        return true;
    }
}
