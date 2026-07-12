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
        foreach ($elements as $element) {
            if (! $element instanceof FusedGeometryElementData) {
                throw new InvalidArgumentException('Geometry element is invalid.');
            }
        }
        usort($elements, static fn (FusedGeometryElementData $a, FusedGeometryElementData $b): int => [$a->key, $a->evidenceRef, self::signature($a)] <=> [$b->key, $b->evidenceRef, self::signature($b)]);
        $identities = [];
        $groups = [];
        foreach ($elements as $element) {
            $signature = self::signature($element);
            foreach ($element->provenance as $provenance) {
                $identity = [
                    $element->key,
                    $element->type,
                    $signature,
                    $provenance['source_type'],
                    $provenance['source_fingerprint'],
                    $provenance['page_number'],
                    $provenance['coordinate_space'],
                    $provenance['coordinate_transform'],
                    $provenance['runtime_version'],
                    $provenance['model_version'],
                ];
                $reference = $provenance['evidence_ref'];
                if (isset($identities[$reference]) && $identities[$reference] !== $identity) {
                    throw new InvalidArgumentException('Geometry evidence identity is inconsistent.');
                }
                $identities[$reference] = $identity;
            }
            $groups[$element->key][$signature][] = $element;
        }
        $fused = [];
        $issues = [];
        foreach ($groups as $key => $variants) {
            $all = array_merge(...array_values($variants));
            $evidence = [];
            foreach ($all as $element) {
                $evidence = [...$evidence, ...$element->evidenceRefs()];
            }
            $evidence = array_values(array_unique($evidence));
            sort($evidence, SORT_STRING);
            if (count($variants) > 1) {
                $issues[] = ['code' => 'geometry_element_conflict', 'severity' => 'blocking', 'element_key' => $key, 'evidence_refs' => $evidence];

                continue;
            }
            $merged = $all[0];
            foreach (array_slice($all, 1) as $element) {
                $merged = $merged->withProvenanceFrom($element);
            }
            $fused[] = $merged;
        }

        return new GeometryFusionResult($fused, $elements, $issues);
    }

    private static function signature(FusedGeometryElementData $element): string
    {
        return json_encode([$element->type, $element->geometry], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
