<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DomainException;

final readonly class OwnerSnapshotSourceHash
{
    public function make(string $queryCanonicalJson, iterable $sourceHashes): string
    {
        $hashes = [];
        foreach ($sourceHashes as $hash) {
            $value = (string) $hash;
            if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
                throw new DomainException('Owner report source hash is invalid.');
            }
            $hashes[] = $value;
        }
        sort($hashes, SORT_STRING);

        return hash('sha256', CanonicalJson::encode([
            'query' => $queryCanonicalJson,
            'sources' => $hashes,
        ]));
    }
}
