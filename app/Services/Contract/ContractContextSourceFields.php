<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;

final class ContractContextSourceFields
{
    public static function type(string $field): ?string
    {
        if ($field === 'contract.date') {
            return 'date';
        }
        if (in_array($field, ['project.name', 'contract.number'], true)
            || preg_match('/^(first_party|second_party)\.(name|legal_name|inn|kpp|ogrn|legal_address|email|phone)$/D', $field) === 1) {
            return 'text';
        }

        return null;
    }

    public static function forContract(Contract $contract): array
    {
        $parties = $contract->parties()->get()->keyBy(static fn ($party): string => $party->getRawOriginal('side'));

        return [
            'project' => ['name' => $contract->project?->name],
            'contract' => ['number' => $contract->number, 'date' => $contract->date?->toDateString()],
            'first_party' => $parties->get('first')?->toArray() ?? [],
            'second_party' => $parties->get('second')?->toArray() ?? [],
        ];
    }

    public static function value(array $context, string $field): ?string
    {
        if (self::type($field) === null) {
            return null;
        }
        [$entity, $attribute] = explode('.', $field, 2);
        $value = $context[$entity][$attribute] ?? null;

        return $value === null ? null : (string) $value;
    }
}
