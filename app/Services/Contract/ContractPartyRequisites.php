<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class ContractPartyRequisites
{
    public const LABELS = [
        'person_type' => 'Вид лица', 'bank_name' => 'Банк', 'bank_bic' => 'БИК / SWIFT',
        'settlement_account' => 'Расчётный счёт / IBAN', 'correspondent_account' => 'Корреспондентский счёт',
        'representative_name' => 'ФИО подписанта', 'representative_position' => 'Должность подписанта',
        'authority_kind' => 'Основание полномочий', 'authority_basis' => 'Документ о полномочиях',
        'power_of_attorney_number' => 'Номер доверенности', 'power_of_attorney_date' => 'Дата доверенности',
        'power_of_attorney_valid_until' => 'Доверенность действует до',
    ];

    public static function validate(array $details, string $path = 'party_details'): array
    {
        $result = [];
        foreach ($details as $field => $value) {
            $label = self::LABELS[$field] ?? null;
            $limit = in_array($field, ['representative_name', 'representative_position'], true) ? 255 : 512;
            if ($label === null || ($value !== null && !is_string($value)) || mb_strlen($value ?? '') > $limit) {
                throw ValidationException::withMessages([$path.'.'.$field => trans_message('contracts.party_requisite_invalid')]);
            }
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;
            $valid = match ($field) {
                'person_type' => $value === null || in_array($value, ['legal_entity', 'individual', 'sole_proprietor'], true),
                'authority_kind' => $value === null || in_array($value, ['charter', 'power_of_attorney', 'law', 'other'], true),
                'power_of_attorney_date', 'power_of_attorney_valid_until' => $value === null || self::date($value),
                'bank_bic' => $value === null || preg_match('/^[A-Z0-9-]{3,34}$/D', $value) === 1,
                'settlement_account', 'correspondent_account' => $value === null || preg_match('/^[A-Za-z0-9 -]{1,64}$/D', $value) === 1,
                default => true,
            };
            if (!$valid) { throw ValidationException::withMessages([$path.'.'.$field => trans_message('contracts.party_requisite_format_invalid', ['label' => $label])]); }
            $result[$field] = $value;
        }
        $issued = $result['power_of_attorney_date'] ?? null;
        $until = $result['power_of_attorney_valid_until'] ?? null;
        if ($issued !== null && $until !== null && $until < $issued) {
            throw ValidationException::withMessages([$path.'.power_of_attorney_valid_until' => trans_message('contracts.party_power_of_attorney_date_invalid')]);
        }
        return $result;
    }

    public static function snapshot(array $party): array
    {
        $details = array_intersect_key(is_array($party['snapshot'] ?? null) ? $party['snapshot'] : $party, self::LABELS);
        return [...array_intersect_key($party, array_flip(['party_key', 'side', 'role', 'role_label', 'counterparty_id', 'linked_organization_id', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'])),
            ...(isset($party['snapshot']['role_label']) ? ['role_label' => $party['snapshot']['role_label']] : []), ...$details];
    }

    public static function directoryBankDetails(mixed $source): array
    {
        if (is_string($source)) { $source = json_decode($source, true); }
        if (!is_array($source)) { return []; }
        $result = [];
        foreach (['bank_name' => ['bank_name', 'bank'], 'bank_bic' => ['bank_bic', 'bic', 'bik'],
            'settlement_account' => ['settlement_account', 'account', 'checking_account'],
            'correspondent_account' => ['correspondent_account', 'correspondent']] as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (is_string($source[$alias] ?? null)) { $result[$field] = $source[$alias]; break; }
            }
        }
        return $result;
    }

    public static function display(string $field, ?string $value): ?string
    {
        return match ($field) {
            'person_type' => ['legal_entity' => 'Юридическое лицо', 'individual' => 'Физическое лицо', 'sole_proprietor' => 'Индивидуальный предприниматель'][$value ?? ''] ?? $value,
            'authority_kind' => ['charter' => 'Устав', 'power_of_attorney' => 'Доверенность', 'law' => 'Закон', 'other' => 'Другое основание'][$value ?? ''] ?? $value,
            default => $value,
        };
    }

    public static function mergeParties(array $parties, array $details): array
    {
        if (count($details) > 20) { throw ValidationException::withMessages(['party_details' => trans_message('contracts.party_details_limit')]); }
        $keys = array_map(static fn (array $party): string => (string) ($party['party_key'] ?? $party['side']), $parties);
        foreach ($details as $key => $fields) {
            if (!in_array($key, $keys, true) || !is_array($fields)) {
                throw ValidationException::withMessages(['party_details.'.$key => trans_message('contracts.party_details_unknown')]);
            }
        }
        return array_map(static function (array $party) use ($details): array {
            $key = $party['party_key'] ?? $party['side'];
            $merged = [...self::snapshot($party), ...self::validate($details[$key] ?? [], 'party_details.'.$key)];
            self::validate(array_intersect_key($merged, self::LABELS), 'party_details.'.$key);
            return $merged;
        }, $parties);
    }

    public static function applyToContract(Contract $contract, array $details): void
    {
        $parties = $contract->parties()->get();
        $merged = self::mergeParties($parties->toArray(), $details);
        foreach ($parties as $index => $party) { $party->update(['snapshot' => $merged[$index]]); }
    }

    private static function date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
