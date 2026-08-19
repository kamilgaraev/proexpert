<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectIdentity
{
    public function identity(string $category, string $entityKey, string $label): string
    {
        $objectType = $this->objectType($label, $entityKey);
        $roomKey = $this->roomKey($entityKey) ?? 'room:unknown';
        $family = match ($category) {
            'sanitary_fixture' => 'sanitary',
            'kitchen_fixture' => 'kitchen',
            'furniture' => 'furniture',
            'equipment' => 'equipment',
            default => 'fixture',
        };
        $fallback = $objectType === 'unknown' ? ':'.$this->normalizeEntityKey($entityKey) : '';

        return 'visual:'.$roomKey.':'.$family.':'.$objectType.$fallback;
    }

    public function normalizeEntityKey(string $entityKey): string
    {
        $normalized = preg_replace('/[.:_-]+/u', '.', mb_strtolower(trim($entityKey)));

        return trim(is_string($normalized) ? $normalized : mb_strtolower(trim($entityKey)), '.');
    }

    public function roomKey(string $entityKey): ?string
    {
        $tokens = array_values(array_filter(explode('.', $this->normalizeEntityKey($entityKey))));
        if (($tokens[0] ?? null) !== 'room' || count($tokens) < 2) {
            return null;
        }

        $objectTokens = [
            'toilet', 'унитаз', 'washbasin', 'sink', 'раковина', 'мойка', 'bath', 'ванна',
            'shower', 'душ', 'bed', 'beds', 'кровать', 'table', 'стол', 'chair', 'стул',
            'sofa', 'диван', 'stove', 'cooktop', 'плита', 'fixture', 'fixtures', 'equipment',
        ];
        $roomTokens = [];
        foreach (array_slice($tokens, 1) as $token) {
            if (in_array($token, $objectTokens, true)) {
                break;
            }
            $roomTokens[] = $token;
        }
        if ($roomTokens === []) {
            $roomTokens[] = $tokens[1];
        }

        return 'room:'.implode('-', $roomTokens);
    }

    public function objectType(string $label, string $entityKey = ''): string
    {
        $value = $label.' '.$this->normalizeEntityKey($entityKey);

        return match (true) {
            preg_match('/унитаз|toilet/iu', $value) === 1 => 'toilet',
            preg_match('/(?:кухон|kitchen).*(?:мойк|раковин|sink)|(?:мойк|раковин|sink).*(?:кухон|kitchen)/iu', $value) === 1 => 'kitchen_sink',
            preg_match('/умываль|раковин|washbasin/iu', $value) === 1 => 'washbasin',
            preg_match('/мойк|sink/iu', $value) === 1 => 'kitchen_sink',
            preg_match('/ванн|bath/iu', $value) === 1 => 'bath',
            preg_match('/душ|shower/iu', $value) === 1 => 'shower',
            preg_match('/кроват|bed/iu', $value) === 1 => 'bed',
            preg_match('/стол|table/iu', $value) === 1 => 'table',
            preg_match('/стул|chair/iu', $value) === 1 => 'chair',
            preg_match('/диван|sofa/iu', $value) === 1 => 'sofa',
            preg_match('/плит|cooktop|stove/iu', $value) === 1 => 'stove',
            default => 'unknown',
        };
    }

    public function canonicalLabel(string $objectType, string $fallback): string
    {
        return match ($objectType) {
            'toilet' => 'Унитаз',
            'kitchen_sink' => 'Кухонная мойка',
            'washbasin' => 'Умывальник',
            'bath' => 'Ванна',
            'shower' => 'Душ',
            'bed' => 'Кровать',
            'table' => 'Стол',
            'chair' => 'Стул',
            'sofa' => 'Диван',
            'stove' => 'Плита',
            default => mb_substr(trim($fallback), 0, 240),
        };
    }
}
