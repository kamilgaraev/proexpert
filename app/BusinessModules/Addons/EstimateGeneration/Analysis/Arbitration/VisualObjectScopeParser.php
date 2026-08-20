<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectScopeParser
{
    private const MAX_KEY_BYTES = 512;

    private const MAX_TOKENS = 32;

    private const MARKERS = [
        'building' => ['building', 'buildingno', 'здание', 'корпус'],
        'section' => ['section', 'секция'],
        'floor' => ['floor', 'level', 'этаж', 'уровень'],
        'room' => ['room', 'помещение', 'комната'],
    ];

    private const OBJECT_TOKENS = [
        'toilet', 'toilets', 'унитаз', 'унитазы', 'washbasin', 'washbasins', 'умывальник', 'умывальники',
        'рукомойник', 'рукомойники', 'sink', 'sinks', 'basin', 'basins', 'раковина', 'раковины', 'мойка',
        'мойки', 'bath', 'bathtub', 'ванна', 'ванны', 'shower', 'showers', 'душ', 'bed', 'beds', 'кровать',
        'кровати', 'table', 'tables', 'стол', 'столы', 'chair', 'chairs', 'стул', 'стулья', 'sofa', 'sofas',
        'диван', 'диваны', 'stove', 'stoves', 'cooktop', 'cooktops', 'плита', 'fixture', 'fixtures',
        'equipment', 'оборудование', 'object', 'объект',
    ];

    public function parse(string $entityKey): VisualObjectScope
    {
        $normalized = preg_replace('/[.:_\-]+/u', '.', mb_strtolower(trim(
            mb_strcut($entityKey, 0, self::MAX_KEY_BYTES, 'UTF-8'),
        )));
        $tokens = array_slice(array_values(array_filter(
            explode('.', trim(is_string($normalized) ? $normalized : '', '.')),
            static fn (string $token): bool => $token !== '',
        )), 0, self::MAX_TOKENS);
        $segments = [];
        $unknown = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $marker = $this->marker($tokens[$index]);
            if ($marker === null) {
                if ($this->isObjectToken($tokens[$index])) {
                    break;
                }
                $unknown[] = $tokens[$index];

                continue;
            }
            $values = [];
            for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                if ($this->marker($tokens[$cursor]) !== null || $this->isObjectToken($tokens[$cursor])) {
                    break;
                }
                $values[] = $tokens[$cursor];
                if ($marker !== 'room') {
                    break;
                }
            }
            if ($values === []) {
                continue;
            }
            $segments[$marker] = $this->canonicalValue($marker, $values);
            $index += count($values);
        }

        return new VisualObjectScope($segments, array_values(array_unique($unknown)));
    }

    private function marker(string $token): ?string
    {
        foreach (self::MARKERS as $marker => $aliases) {
            if (in_array($token, $aliases, true)) {
                return $marker;
            }
        }

        return null;
    }

    /** @param list<string> $values */
    private function canonicalValue(string $marker, array $values): string
    {
        if ($marker === 'room') {
            $joined = str_replace(['с-у', 'сан-узел'], 'bathroom', implode('-', $values));
            $parts = array_map(static fn (string $token): string => match ($token) {
                'kitchen', 'кухня', 'кух', 'кухонная' => 'kitchen',
                'bathroom', 'санузел', 'санитарный', 'ванная', 'туалет', 'wc' => 'bathroom',
                default => $token,
            }, explode('-', $joined));

            return implode('-', array_values(array_unique($parts)));
        }
        $value = $values[0];

        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            return (string) ((int) $value);
        }
        if (mb_strlen($value) === 1) {
            return strtr($value, [
                'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
                'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm',
                'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
                'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh',
                'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            ]);
        }

        return $value;
    }

    private function isObjectToken(string $token): bool
    {
        return in_array($token, self::OBJECT_TOKENS, true);
    }
}
