<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final class GeometryFusionService
{
    public function fuse(array $elements): GeometryFusionResult
    {
        if (! array_is_list($elements)) {
            throw new InvalidArgumentException('Geometry elements must be a list.');
        }
        usort($elements, static fn (FusedGeometryElementData $a, FusedGeometryElementData $b): int => [$a->key, $a->evidenceRef] <=> [$b->key, $b->evidenceRef]);
        $sourceElements = $elements;
        $fused = [];
        $issues = [];
        foreach ($elements as $element) {
            if (! $element instanceof FusedGeometryElementData) {
                throw new InvalidArgumentException('Geometry element is invalid.');
            }
            $signature = json_encode([$element->type, $element->geometry], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (! isset($fused[$element->key])) {
                $fused[$element->key] = ['element' => $element, 'signature' => $signature, 'evidence' => [$element->evidenceRef]];

                continue;
            }
            if ($fused[$element->key]['signature'] === $signature) {
                $fused[$element->key]['evidence'][] = $element->evidenceRef;
                $fused[$element->key]['element'] = $fused[$element->key]['element']->withProvenanceFrom($element);

                continue;
            }
            $evidence = array_values(array_unique([...$fused[$element->key]['evidence'], $element->evidenceRef]));
            sort($evidence, SORT_STRING);
            $issues[$element->key] = ['code' => 'geometry_element_conflict', 'severity' => 'blocking', 'element_key' => $element->key, 'evidence_refs' => $evidence];
        }
        foreach (array_keys($issues) as $conflictedKey) {
            unset($fused[$conflictedKey]);
        }
        ksort($fused, SORT_STRING);
        ksort($issues, SORT_STRING);

        return new GeometryFusionResult(array_values(array_column($fused, 'element')), $sourceElements, array_values($issues));
    }
}
