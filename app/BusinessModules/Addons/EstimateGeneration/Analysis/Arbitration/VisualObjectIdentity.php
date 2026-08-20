<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectIdentity
{
    private const TYPE_ALIASES = [
        'toilet' => [['унитаз'], ['унитазы'], ['toilet'], ['toilets']],
        'bath' => [['ванна'], ['ванны'], ['bath'], ['bathtub'], ['bathtubs']],
        'shower' => [['душ'], ['душевая', 'кабина'], ['shower'], ['showers']],
        'bed' => [['кровать'], ['кровати'], ['bed'], ['beds']],
        'table' => [['стол'], ['столы'], ['table'], ['tables']],
        'chair' => [['стул'], ['стулья'], ['chair'], ['chairs']],
        'sofa' => [['диван'], ['диваны'], ['sofa'], ['sofas']],
        'stove' => [
            ['плита'], ['кухонная', 'плита'], ['газовая', 'плита'], ['электрическая', 'плита'],
            ['cooktop'], ['cooktops'], ['stove'], ['stoves'],
        ],
    ];

    private const BLOCKED_PHRASES = [
        ['плитка'], ['плиточный'], ['плиточная'], ['плита', 'перекрытия'], ['плиты', 'перекрытия'],
        ['floor', 'slab'], ['concrete', 'slab'], ['столешница'], ['столовая'], ['ванная', 'комната'],
        ['toiletry'], ['stovepipe'], ['условное', 'обозначение'], ['conventional', 'symbol'],
        ['строительный', 'материал'], ['building', 'material'],
    ];

    public function __construct(
        private VisualObjectInstanceParser $instances = new VisualObjectInstanceParser,
        private VisualObjectScopeParser $scopeParser = new VisualObjectScopeParser,
    ) {}

    public function identity(string $category, string $entityKey, string $label): string
    {
        $objectType = $this->classification($label, $entityKey, $category)->objectType;
        if ($objectType === 'unknown') {
            $entityObjectType = $this->classification('', $entityKey, $category)->objectType;
            if ($entityObjectType !== 'unknown') {
                $objectType = $entityObjectType;
            }
        }
        $scope = $this->scopeParser->parse($entityKey);
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

        return 'visual:'.$scope->identityPrefix().':'.$family.':'.$objectType.$fallback
            .$this->instances->parse($entityKey)->identitySuffix();
    }

    public function normalizeEntityKey(string $entityKey): string
    {
        $normalized = preg_replace('/[.:_\-]+/u', '.', mb_strtolower(trim($entityKey)));

        return trim(is_string($normalized) ? $normalized : mb_strtolower(trim($entityKey)), '.');
    }

    public function roomKey(string $entityKey): ?string
    {
        return $this->scopeParser->parse($entityKey)->roomKey();
    }

    public function objectType(string $label, string $entityKey = '', ?string $category = null): string
    {
        return $this->classification($label, $entityKey, $category)->objectType;
    }

    public function classification(string $label, string $entityKey = '', ?string $category = null): VisualObjectClassification
    {
        $labelTokens = $this->tokens($label);
        $entityTokens = $this->tokens($this->normalizeEntityKey($entityKey));
        $blocked = $this->containsPhrase($labelTokens, self::BLOCKED_PHRASES);
        $sink = $this->sinkClassification($labelTokens, $entityTokens, $entityKey, $category);
        if ($sink !== null) {
            return $blocked
                ? new VisualObjectClassification('unknown', 'object_type_conflicted')
                : $sink;
        }

        $labelTypes = $this->types($labelTokens);
        $entityTypes = $this->types($entityTokens);
        $allTypes = array_values(array_unique([...$labelTypes, ...$entityTypes]));
        if ($blocked) {
            return new VisualObjectClassification('unknown', $allTypes === []
                ? 'object_type_requires_confirmation'
                : 'object_type_conflicted');
        }
        if (count($allTypes) !== 1) {
            return new VisualObjectClassification('unknown', count($allTypes) > 1 ? 'object_type_conflicted' : null);
        }
        $objectType = $allTypes[0];
        if (! $this->categoryAllows($category, $objectType) || ! $this->roomAllows($entityKey, $objectType)) {
            return new VisualObjectClassification('unknown', 'object_type_conflicted');
        }

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

    /** @param list<string> $labelTokens @param list<string> $entityTokens */
    private function sinkClassification(array $labelTokens, array $entityTokens, string $entityKey, ?string $category): ?VisualObjectClassification
    {
        $tokens = [...$labelTokens, ...$entityTokens];
        if (! $this->containsPhrase($tokens, [
            ['мойка'], ['мойки'], ['раковина'], ['раковины'], ['sink'], ['sinks'], ['basin'], ['basins'],
            ['умывальник'], ['умывальники'], ['рукомойник'], ['washbasin'], ['washbasins'],
        ])) {
            return null;
        }
        $room = $this->roomKey($entityKey);
        $kitchen = $room === 'room:kitchen'
            || $category === 'kitchen_fixture'
            || $this->containsPhrase($tokens, [['кухонная'], ['кухонный'], ['kitchen']]);
        $bathroom = $room === 'room:bathroom'
            || $category === 'sanitary_fixture'
            || $this->containsPhrase($tokens, [
                ['умывальник'], ['умывальники'], ['рукомойник'], ['washbasin'], ['washbasins'],
                ['basin'], ['basins'], ['санузел'], ['ванная'], ['bathroom'], ['wc'],
            ]);
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

    /** @param list<string> $tokens @return list<string> */
    private function types(array $tokens): array
    {
        $types = [];
        foreach (self::TYPE_ALIASES as $type => $phrases) {
            if ($this->containsPhrase($tokens, $phrases)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function categoryAllows(?string $category, string $objectType): bool
    {
        return match ($category) {
            null, 'unknown_fixture', 'equipment' => true,
            'sanitary_fixture' => in_array($objectType, ['toilet', 'washbasin', 'bath', 'shower'], true),
            'kitchen_fixture' => in_array($objectType, ['kitchen_sink', 'stove'], true),
            'furniture' => in_array($objectType, ['bed', 'table', 'chair', 'sofa'], true),
            default => false,
        };
    }

    private function roomAllows(string $entityKey, string $objectType): bool
    {
        return match ($this->roomKey($entityKey)) {
            'room:kitchen' => in_array($objectType, ['kitchen_sink', 'stove', 'table', 'chair'], true),
            'room:bathroom' => in_array($objectType, ['toilet', 'washbasin', 'bath', 'shower'], true),
            default => true,
        };
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(mb_strcut($value, 0, 512, 'UTF-8')), $matches);

        return array_slice(array_values(array_filter($matches[0] ?? [], 'is_string')), 0, 64);
    }

    /** @param list<string> $tokens @param list<list<string>> $phrases */
    private function containsPhrase(array $tokens, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $length = count($phrase);
            for ($index = 0; $index <= count($tokens) - $length; $index++) {
                if (array_slice($tokens, $index, $length) === $phrase) {
                    return true;
                }
            }
        }

        return false;
    }
}
