<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use InvalidArgumentException;

final class GeometryProjectionSourceVersion
{
    /** @param list<array{id:int,source_version:string}> $documents */
    public static function fromDocuments(array $documents): string
    {
        if ($documents === [] || count($documents) > 10_000) {
            throw new InvalidArgumentException('geometry_source_set_invalid');
        }
        $identities = [];
        foreach ($documents as $document) {
            if (! is_int($document['id'] ?? null) || $document['id'] < 1
                || ! is_string($document['source_version'] ?? null)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['source_version']) !== 1) {
                throw new InvalidArgumentException('geometry_source_set_invalid');
            }
            $identities[] = $document['id'].':'.$document['source_version'];
        }
        sort($identities, SORT_STRING);

        return 'sha256:'.hash('sha256', json_encode($identities, JSON_THROW_ON_ERROR));
    }
}
