<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractRevisionParties
{
    public static function key(array $party): string
    {
        $key = $party['party_key'] ?? $party['side'] ?? null;
        if (!is_string($key) || ($key !== 'first' && $key !== 'second' && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key) !== 1)) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        return $key;
    }

    public static function confirmed(array $parties, array $confirmations, string $hash): bool
    {
        if (count($parties) < 2 || count($parties) > 20) { return false; }
        $required = array_map(self::key(...), $parties);
        if (count(array_unique($required)) !== count($required)) { return false; }
        $actual = [];
        foreach ($confirmations as $item) {
            $item = (array) $item;
            if (!is_string($item['content_hash'] ?? null) || !hash_equals($hash, $item['content_hash'])) { return false; }
            $key = $item['party_key'] ?? $item['side'] ?? null;
            if (!is_string($key) || isset($actual[$key]) || !in_array($key, $required, true)) { return false; }
            $party = $parties[array_search($key, $required, true)];
            if (($party['linked_organization_id'] ?? null) !== null) {
                if (($item['kind'] ?? null) !== 'connected' || (int) ($item['party_organization_id'] ?? 0) !== (int) $party['linked_organization_id']) { return false; }
            } elseif (($item['kind'] ?? null) !== 'external') { return false; }
            $actual[$key] = true;
        }
        return count($actual) === count($required);
    }

    public static function externalKey(array $external): string
    {
        if (isset($external['party_key'], $external['side']) && $external['party_key'] !== $external['side']) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        return self::key($external);
    }
}
