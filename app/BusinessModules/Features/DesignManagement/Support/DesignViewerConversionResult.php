<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use RuntimeException;

final readonly class DesignViewerConversionResult
{
    private function __construct(
        private string $format,
        private bool $raw,
        private int $localIdCount,
        private int $categoryCount,
        private int $sampleCount,
        private int $representationCount,
        private int $shellCount,
        private ?array $boundingBox,
        private array $warnings,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        $metrics = $payload['metrics'] ?? $payload;
        $metrics = is_array($metrics) ? $metrics : [];

        return new self(
            format: (string) ($metrics['format'] ?? 'thatopen_frag'),
            raw: (bool) ($metrics['raw'] ?? false),
            localIdCount: self::integerMetric($metrics, 'local_id_count'),
            categoryCount: self::integerMetric($metrics, 'category_count'),
            sampleCount: self::integerMetric($metrics, 'sample_count'),
            representationCount: self::integerMetric($metrics, 'representation_count'),
            shellCount: self::integerMetric($metrics, 'shell_count'),
            boundingBox: self::boundingBox($metrics['bounding_box'] ?? $metrics['bbox'] ?? null),
            warnings: self::warnings($payload['warnings'] ?? $metrics['warnings'] ?? []),
        );
    }

    public function assertRenderableGeometry(): void
    {
        if ($this->localIdCount <= 0 || $this->sampleCount <= 0 || $this->representationCount <= 0) {
            throw new RuntimeException('Prepared viewer file does not contain renderable BIM geometry.');
        }

        if (!$this->hasValidBoundingBox()) {
            throw new RuntimeException('Prepared viewer file has an invalid BIM bounding box.');
        }
    }

    public function metadata(): array
    {
        return [
            'format' => $this->format,
            'raw' => $this->raw,
            'geometry' => [
                'local_id_count' => $this->localIdCount,
                'category_count' => $this->categoryCount,
                'sample_count' => $this->sampleCount,
                'representation_count' => $this->representationCount,
                'shell_count' => $this->shellCount,
                'bounding_box' => $this->boundingBox,
            ],
            'warnings' => $this->warnings,
        ];
    }

    private function hasValidBoundingBox(): bool
    {
        if ($this->boundingBox === null) {
            return false;
        }

        $min = $this->boundingBox['min'] ?? null;
        $max = $this->boundingBox['max'] ?? null;

        if (!is_array($min) || !is_array($max)) {
            return false;
        }

        foreach (['x', 'y', 'z'] as $axis) {
            if (
                !isset($min[$axis], $max[$axis])
                || !is_finite((float) $min[$axis])
                || !is_finite((float) $max[$axis])
            ) {
                return false;
            }

            if ((float) $max[$axis] < (float) $min[$axis]) {
                return false;
            }
        }

        return ((float) $max['x'] > (float) $min['x'])
            || ((float) $max['y'] > (float) $min['y'])
            || ((float) $max['z'] > (float) $min['z']);
    }

    private static function integerMetric(array $metrics, string $key): int
    {
        $value = $metrics[$key] ?? 0;

        return max(0, (int) $value);
    }

    private static function boundingBox(mixed $value): ?array
    {
        if (!is_array($value) || !isset($value['min'], $value['max'])) {
            return null;
        }

        $min = self::vector($value['min']);
        $max = self::vector($value['max']);

        if ($min === null || $max === null) {
            return null;
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    private static function vector(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        foreach (['x', 'y', 'z'] as $axis) {
            if (!array_key_exists($axis, $value) || !is_numeric($value[$axis])) {
                return null;
            }
        }

        return [
            'x' => (float) $value['x'],
            'y' => (float) $value['y'],
            'z' => (float) $value['z'],
        ];
    }

    private static function warnings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $warning): string => (string) $warning, $value),
            static fn (string $warning): bool => $warning !== ''
        ));
    }
}
