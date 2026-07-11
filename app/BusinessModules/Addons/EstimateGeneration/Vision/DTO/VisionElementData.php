<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionElementData
{
    private const TYPES = ['room', 'wall', 'opening', 'dimension', 'axis', 'engineering_element', 'text'];

    /** @param array<int, array{0: float, 1: float}> $polygon */
    public function __construct(
        public string $key,
        public string $type,
        public ?string $label,
        public array $polygon,
        public float $confidence,
        public string $evidenceRef,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $key) !== 1
            || ! in_array($type, self::TYPES, true) || ! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0
            || ($label !== null && (mb_strlen($label) > 160 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $label) === 1))
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $evidenceRef) !== 1
            || count($polygon) < ($type === 'room' ? 3 : 2) || count($polygon) > 64) {
            throw new VisionContractException('invalid_element');
        }
        foreach ($polygon as $point) {
            if (count($point) !== 2 || ! is_finite($point[0]) || ! is_finite($point[1])
                || $point[0] < 0.0 || $point[0] > 1.0 || $point[1] < 0.0 || $point[1] > 1.0) {
                throw new VisionContractException('invalid_polygon');
            }
        }
        $pointKeys = array_map(static fn (array $point): string => sprintf('%.12F:%.12F', $point[0], $point[1]), $polygon);
        if (count($pointKeys) !== count(array_unique($pointKeys))) {
            throw new VisionContractException('repeated_polygon_point');
        }
        if (count($polygon) >= 3 && $this->selfIntersects($polygon)) {
            throw new VisionContractException('self_intersecting_polygon');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! self::hasExactKeys($data, ['key', 'type', 'label', 'polygon', 'confidence', 'evidence_ref'])
            || ! is_string($data['key']) || ! is_string($data['type']) || ! is_array($data['polygon'])
            || $data['label'] !== null && ! is_string($data['label'])
            || ! is_int($data['confidence']) && ! is_float($data['confidence']) || ! is_string($data['evidence_ref'])) {
            throw new VisionContractException('invalid_element');
        }
        $polygon = [];
        foreach ($data['polygon'] as $point) {
            if (! is_array($point) || count($point) !== 2 || (! is_int($point[0]) && ! is_float($point[0])) || (! is_int($point[1]) && ! is_float($point[1]))) {
                throw new VisionContractException('invalid_polygon');
            }
            $polygon[] = [(float) $point[0], (float) $point[1]];
        }

        return new self($data['key'], $data['type'], $data['label'], $polygon, (float) $data['confidence'], $data['evidence_ref']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'type' => $this->type, 'label' => $this->label, 'polygon' => $this->polygon, 'confidence' => $this->confidence, 'evidence_ref' => $this->evidenceRef];
    }

    /** @param array<int, array{0: float, 1: float}> $polygon */
    private function selfIntersects(array $polygon): bool
    {
        $count = count($polygon);
        $area = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            $area += $polygon[$i][0] * $polygon[$next][1] - $polygon[$next][0] * $polygon[$i][1];
        }
        if (abs($area) < 1.0e-10) {
            return true;
        }
        for ($i = 0; $i < $count; $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % $count];
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i || $j === ($i + 1) % $count || ($i === 0 && $j === $count - 1)) {
                    continue;
                }
                $c = $polygon[$j];
                $d = $polygon[($j + 1) % $count];
                if ($this->segmentsIntersect($a, $b, $c, $d)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c @param array{0: float, 1: float} $d */
    private function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $cross = static fn (array $p, array $q, array $r): float => ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
        $abC = $cross($a, $b, $c);
        $abD = $cross($a, $b, $d);
        $cdA = $cross($c, $d, $a);
        $cdB = $cross($c, $d, $b);

        return (($abC > 1.0e-10 && $abD < -1.0e-10) || ($abC < -1.0e-10 && $abD > 1.0e-10))
            && (($cdA > 1.0e-10 && $cdB < -1.0e-10) || ($cdA < -1.0e-10 && $cdB > 1.0e-10));
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
