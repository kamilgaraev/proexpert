<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final readonly class DocumentFloorIdentityResolver
{
    public function resolve(?string $filename, ?string $title = null): ?string
    {
        $floors = [];
        foreach ([$filename, $title] as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }
            if ($this->containsFloorCollection($source)) {
                return null;
            }
            foreach ($this->floorNumbers($source) as $floor) {
                $floors[$floor] = true;
            }
        }

        if (count($floors) !== 1) {
            return null;
        }

        return 'floor-'.array_key_first($floors);
    }

    private function containsFloorCollection(string $source): bool
    {
        $source = mb_strtolower(rawurldecode($source), 'UTF-8');
        if (! str_contains($source, 'этаж')
            && ! str_contains($source, 'floor')
            && ! str_contains($source, 'level')) {
            return false;
        }

        $token = '(?:[1-9][0-9]?|i{1,2})';
        $ordinal = '(?:\s*-\s*(?:го|й|ый|ой)|st|nd|rd|th)?';

        return preg_match(
            '/(?<![\p{L}\p{N}])'.$token.$ordinal.'\s*[-–—,\/&]\s*'.$token.$ordinal.'(?![\p{L}\p{N}])/u',
            $source,
        ) === 1 || preg_match(
            '/(?<![\p{L}\p{N}])'.$token.$ordinal.'\s+(?:и|and)\s+'.$token.$ordinal.'(?![\p{L}\p{N}])/u',
            $source,
        ) === 1;
    }

    /** @return list<int> */
    private function floorNumbers(string $source): array
    {
        $source = mb_strtolower(rawurldecode($source), 'UTF-8');
        $token = '(?:[1-9][0-9]?|i{1,2})';
        $russianMarker = 'этаж[а-я]*';
        $englishMarker = '(?:floor|level)s?';
        $patterns = [
            '/(?<![\p{L}\p{N}])('.$token.')(?:\s*-\s*(?:го|й|ый|ой))?[\s._-]+'.$russianMarker.'(?!\p{L})/u',
            '/(?<![\p{L}\p{N}])'.$russianMarker.'[\s._-]+(?:№|no\.?|#)?\s*('.$token.')(?![\p{L}\p{N}])/u',
            '/(?<![\p{L}\p{N}])('.$token.')(?:st|nd|rd|th)?[\s._-]+'.$englishMarker.'(?!\p{L})/u',
            '/(?<![\p{L}\p{N}])'.$englishMarker.'[\s._-]+(?:no\.?|#)?\s*('.$token.')(?![\p{L}\p{N}])/u',
        ];
        $floors = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $value) {
                $floor = $this->floorNumber((string) $value);
                if ($floor !== null) {
                    $floors[$floor] = true;
                }
            }
        }

        $sharedMarkerPatterns = [
            '/(?<![\p{L}\p{N}])('.$token.')\s*(?:,|\/|&|\bи\b|\band\b)\s*('.$token.')\s+'.$russianMarker.'(?!\p{L})/u',
            '/(?<![\p{L}\p{N}])('.$token.')\s*(?:,|\/|&|\band\b)\s*('.$token.')\s+'.$englishMarker.'(?!\p{L})/u',
        ];
        foreach ($sharedMarkerPatterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                foreach ([$match[1] ?? null, $match[2] ?? null] as $value) {
                    $floor = is_string($value) ? $this->floorNumber($value) : null;
                    if ($floor !== null) {
                        $floors[$floor] = true;
                    }
                }
            }
        }

        return array_map('intval', array_keys($floors));
    }

    private function floorNumber(string $value): ?int
    {
        return match ($value) {
            'i' => 1,
            'ii' => 2,
            default => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 99
                ? (int) $value
                : null,
        };
    }
}
