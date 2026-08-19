<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectIdentity
{
    public function __construct(
        private VisualObjectInstanceParser $instances = new VisualObjectInstanceParser,
    ) {}

    public function identity(string $category, string $entityKey, string $label): string
    {
        $objectType = $this->classification($label, $entityKey, $category)->objectType;
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
            ? ':unknown:'.substr(hash('sha256', strlen($entityKey).'|'.mb_strtolower(
                mb_strcut($entityKey, 0, 512, 'UTF-8'),
            )), 0, 16)
            : '';
        $instance = $this->instances->parse($entityKey)->identitySuffix();

        return 'visual:'.$roomKey.':'.$family.':'.$objectType.$fallback.$instance;
    }

    public function normalizeEntityKey(string $entityKey): string
    {
        $normalized = preg_replace('/[.:_-]+/u', '.', mb_strtolower(trim($entityKey)));

        return trim(is_string($normalized) ? $normalized : mb_strtolower(trim($entityKey)), '.');
    }

    public function roomKey(string $entityKey): ?string
    {
        $tokens = array_values(array_filter(
            explode('.', $this->normalizeEntityKey($entityKey)),
            static fn (string $token): bool => $token !== '',
        ));
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
        return $this->classification($label, $entityKey, $category)->objectType;
    }

    public function classification(string $label, string $entityKey = '', ?string $category = null): VisualObjectClassification
    {
        $normalizedEntityKey = $this->normalizeEntityKey($entityKey);
        $value = $label.' '.$normalizedEntityKey;
        $roomKey = $this->roomKey($entityKey);
        $roomTokens = $roomKey === null ? [] : explode('-', mb_substr($roomKey, 5));
        $hasSinkTerm = preg_match('/мойк|раковин|умываль|рукомой|washbasin|\bbasin\b|\bsinks?\b/iu', $value) === 1;
        if ($hasSinkTerm) {
            $kitchenSignals = [
                in_array('kitchen', $roomTokens, true),
                $category === 'kitchen_fixture',
                preg_match('/кухон|\bkitchen\b/iu', $normalizedEntityKey) === 1,
                preg_match('/кухон|\bkitchen\b/iu', $label) === 1,
            ];
            $bathroomSignals = [
                in_array('bathroom', $roomTokens, true),
                $category === 'sanitary_fixture',
                preg_match('/умываль|рукомой|washbasin|\bbasin\b/iu', $normalizedEntityKey) === 1,
                preg_match('/умываль|рукомой|washbasin|\bbasin\b/iu', $label) === 1,
                preg_match('/сануз|ванн|bathroom|\bwc\b/iu', $label) === 1,
            ];
            $kitchen = in_array(true, $kitchenSignals, true);
            $bathroom = in_array(true, $bathroomSignals, true);
            if ($kitchen && $bathroom) {
                return new VisualObjectClassification('unknown', 'object_type_conflicted');
            }
            if ($kitchen) {
                return new VisualObjectClassification('kitchen_sink');
            }
            if ($bathroom) {
                return new VisualObjectClassification('washbasin');
            }

            return new VisualObjectClassification('unknown', 'object_type_requires_confirmation');
        }

        $objectType = match (true) {
            preg_match('/унитаз|toilet/iu', $value) === 1 => 'toilet',
            preg_match('/ванн|\bbath\b/iu', $value) === 1 => 'bath',
            preg_match('/душ|shower/iu', $value) === 1 => 'shower',
            preg_match('/кроват|\bbeds?\b/iu', $value) === 1 => 'bed',
            preg_match('/стол|\btables?\b/iu', $value) === 1 => 'table',
            preg_match('/стул|\bchairs?\b/iu', $value) === 1 => 'chair',
            preg_match('/диван|\bsofas?\b/iu', $value) === 1 => 'sofa',
            preg_match('/плит|cooktop|stove/iu', $value) === 1 => 'stove',
            default => 'unknown',
        };

        return new VisualObjectClassification($objectType);
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
