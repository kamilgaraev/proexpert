<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectIdentity
{
    public function identity(string $category, string $entityKey, string $label): string
    {
        $objectType = $this->objectType($label, $entityKey, $category);
        $roomKey = $this->roomKey($entityKey) ?? 'room:unknown';
        $family = match ($objectType) {
            'toilet', 'washbasin', 'bath', 'shower' => 'sanitary',
            'kitchen_sink', 'stove' => 'kitchen',
            'bed', 'table', 'chair', 'sofa' => 'furniture',
            default => match ($category) {
                'sanitary_fixture' => 'sanitary',
                'kitchen_fixture' => 'kitchen',
                'furniture' => 'furniture',
                'equipment' => 'equipment',
                default => 'fixture',
            },
        };
        $fallback = $objectType === 'unknown'
            ? ':unknown:'.substr(hash('sha256', $this->normalizeEntityKey($entityKey)), 0, 16)
            : '';
        $instance = $this->instanceKey($entityKey, $objectType);

        return 'visual:'.$roomKey.':'.$family.':'.$objectType.$fallback.$instance;
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

        $roomTokens = [];
        $roomParts = array_slice($tokens, 1);
        for ($index = 0; $index < count($roomParts); $index++) {
            $token = $roomParts[$index];
            if ($this->isObjectToken($token)) {
                break;
            }
            if (($token === 'с' && ($roomParts[$index + 1] ?? null) === 'у')
                || ($token === 'сан' && ($roomParts[$index + 1] ?? null) === 'узел')) {
                $roomTokens[] = 'bathroom';
                $index++;

                continue;
            }
            $roomTokens[] = $this->canonicalRoomToken($token);
        }
        if ($roomTokens === []) {
            $roomTokens[] = $tokens[1];
        }

        return 'room:'.implode('-', $roomTokens);
    }

    public function objectType(string $label, string $entityKey = '', ?string $category = null): string
    {
        $value = $label.' '.$this->normalizeEntityKey($entityKey);
        $roomKey = $this->roomKey($entityKey);
        $roomTokens = $roomKey === null ? [] : explode('-', mb_substr($roomKey, 5));
        $bathroomContext = in_array('bathroom', $roomTokens, true) || $category === 'sanitary_fixture';
        $kitchenContext = in_array('kitchen', $roomTokens, true) || $category === 'kitchen_fixture';
        $kitchenSignal = preg_match('/(?:кухон|kitchen).*(?:мойк|раковин|sink)|(?:мойк|раковин|sink).*(?:кухон|kitchen)/iu', $value) === 1;
        $bathroomSignal = preg_match('/(?:сануз|ванн|bathroom|wc).*(?:мойк|раковин|sink|basin)|(?:мойк|раковин|sink|basin).*(?:сануз|ванн|bathroom|wc)/iu', $value) === 1;

        return match (true) {
            preg_match('/унитаз|toilet/iu', $value) === 1 => 'toilet',
            $kitchenSignal && $bathroomSignal => 'unknown',
            $kitchenSignal => 'kitchen_sink',
            $bathroomSignal => 'washbasin',
            preg_match('/умываль|рукомой|washbasin|\bbasin\b/iu', $value) === 1 => 'washbasin',
            preg_match('/мойк|раковин|sink/iu', $value) === 1 && $bathroomContext && ! $kitchenContext => 'washbasin',
            preg_match('/мойк|раковин|sink/iu', $value) === 1 && $kitchenContext && ! $bathroomContext => 'kitchen_sink',
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

    private function instanceKey(string $entityKey, string $objectType): string
    {
        if ($objectType === 'unknown') {
            return '';
        }
        $tokens = array_values(array_filter(explode('.', $this->normalizeEntityKey($entityKey))));
        $objectIndex = null;
        foreach ($tokens as $index => $token) {
            if ($this->isObjectToken($token)) {
                $objectIndex = $index;
            }
        }
        if ($objectIndex === null || $objectIndex === array_key_last($tokens)) {
            return '';
        }
        $instance = implode('.', array_slice($tokens, $objectIndex + 1));
        if (preg_match('/^[0-9]+$/D', $instance) === 1) {
            $instance = ltrim($instance, '0');
            $instance = $instance === '' ? '0' : $instance;
        }

        return ':instance:'.substr(hash('sha256', $instance), 0, 16);
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
            default => 'Объект на плане',
        };
    }

    public function canonicalCategory(string $objectType, string $fallback): string
    {
        return match ($objectType) {
            'toilet', 'washbasin', 'bath', 'shower' => 'sanitary_fixture',
            'kitchen_sink', 'stove' => 'kitchen_fixture',
            'bed', 'table', 'chair', 'sofa' => 'furniture',
            default => $fallback,
        };
    }

    private function canonicalRoomToken(string $token): string
    {
        return match ($token) {
            'kitchen', 'кухня', 'кух', 'кухонная' => 'kitchen',
            'bathroom', 'санузел', 'санитарный', 'ванная', 'туалет', 'wc' => 'bathroom',
            default => $token,
        };
    }

    private function isObjectToken(string $token): bool
    {
        return in_array($token, [
            'toilet', 'унитаз', 'washbasin', 'умывальник', 'рукомойник',
            'sink', 'basin', 'раковина', 'мойка', 'bath', 'ванна', 'shower', 'душ', 'bed', 'beds',
            'кровать', 'кровати', 'table', 'стол', 'chair', 'стул', 'sofa', 'диван',
            'stove', 'cooktop', 'плита', 'fixture', 'fixtures', 'equipment', 'оборудование',
        ], true);
    }
}
