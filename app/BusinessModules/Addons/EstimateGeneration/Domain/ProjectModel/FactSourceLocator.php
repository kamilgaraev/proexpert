<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

/** Exact document provenance only; this does not measure geometry or verify the observed text. */
final readonly class FactSourceLocator
{
    public function __construct(public array $value)
    {
        if (! self::valid($value)) {
            throw new InvalidArgumentException('fact_source_locator_invalid');
        }
    }

    public static function valid(mixed $source): bool
    {
        if (! is_array($source) || count($source) !== 3
            || array_diff(array_keys($source), ['kind', 'coordinate_space', 'value']) !== []) {
            return false;
        }
        $value = $source['value'] ?? null;
        if (($source['kind'] ?? null) === 'native') {
            return ($source['coordinate_space'] ?? null) === 'native' && is_string($value)
                && mb_strlen($value) <= 240 && preg_match('~^(?:pdf|image|cad|xlsx):(?!.*\\\\)[^\x00-\x1F]{1,220}$~uD', $value) === 1;
        }
        if (($source['kind'] ?? null) !== 'polygon' || ($source['coordinate_space'] ?? null) !== 'normalized_source_v1'
            || ! is_array($value) || ! array_is_list($value) || count($value) < 2 || count($value) > 64) {
            return false;
        }
        $unique = [];
        foreach ($value as $point) {
            if (! is_array($point) || array_keys($point) !== [0, 1]) {
                return false;
            }
            foreach ($point as $number) {
                if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number) || $number < 0 || $number > 1) {
                    return false;
                }
            }
            $unique[] = sprintf('%.12F:%.12F', $point[0], $point[1]);
        }

        // The public Vision contract also permits horizontal/vertical line segments.
        return count(array_unique($unique)) === count($value);
    }

    public static function fromLocator(array $locator): ?self
    {
        $source = $locator['fact_source'] ?? null;

        return self::valid($source) ? new self($source) : null;
    }

    /** A line retains its exact points, but must not be presented as an area. */
    public function region(): ?array
    {
        if ($this->value['kind'] !== 'polygon') {
            return null;
        }
        $points = $this->value['value'];
        $left = min(array_column($points, 0));
        $top = min(array_column($points, 1));
        $width = max(array_column($points, 0)) - $left;
        $height = max(array_column($points, 1)) - $top;

        return $width > 0 && $height > 0 ? ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height] : null;
    }
}
