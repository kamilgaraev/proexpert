<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectInstanceParser
{
    private const MAX_ENTITY_BYTES = 512;

    private const MAX_ORDINAL_DIGITS = 18;

    private const OBJECT_TOKENS = [
        'toilet', 'унитаз', 'washbasin', 'умывальник', 'рукомойник',
        'sink', 'basin', 'раковина', 'мойка', 'bath', 'ванна', 'shower', 'душ',
        'bed', 'beds', 'кровать', 'кровати', 'table', 'стол', 'chair', 'стул',
        'sofa', 'диван', 'stove', 'cooktop', 'плита', 'fixture', 'fixtures',
        'equipment', 'оборудование', 'window', 'windows', 'окно', 'окна',
        'door', 'doors', 'дверь', 'двери',
    ];

    public function parse(string $entityKey): VisualObjectInstance
    {
        $bounded = mb_strcut($entityKey, 0, self::MAX_ENTITY_BYTES, 'UTF-8');
        $pattern = '/(?:^|[._:-])('.implode('|', array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            self::OBJECT_TOKENS,
        )).')(?=$|[._:-])/iu';
        $matches = [];
        preg_match_all($pattern, mb_strtolower($bounded), $matches, PREG_OFFSET_CAPTURE);
        $objects = $matches[1] ?? [];
        if ($objects === []) {
            return new VisualObjectInstance('absent', null);
        }

        $last = $objects[array_key_last($objects)];
        $object = is_array($last) && is_string($last[0] ?? null) ? $last[0] : '';
        $offset = is_array($last) && is_int($last[1] ?? null) ? $last[1] : strlen($bounded);
        $suffix = substr($bounded, $offset + strlen($object));
        if ($suffix === '') {
            return new VisualObjectInstance('absent', null);
        }
        if (preg_match('/^[._:-](.*)$/usD', $suffix, $suffixMatch) !== 1) {
            return $this->unsupported($suffix, $entityKey);
        }

        $token = $suffixMatch[1];
        if (preg_match('/^[0-9]+$/D', $token) === 1 && strlen($token) <= self::MAX_ORDINAL_DIGITS) {
            $canonical = ltrim($token, '0');

            return new VisualObjectInstance('ordinal', $canonical === '' ? '0' : $canonical);
        }

        return $this->unsupported($token, $entityKey);
    }

    private function unsupported(string $token, string $entityKey): VisualObjectInstance
    {
        $fingerprintInput = strlen($entityKey).'|'.strlen($token).'|'.$token;

        return new VisualObjectInstance('unsupported', substr(hash('sha256', $fingerprintInput), 0, 16));
    }
}
