<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use InvalidArgumentException;

final readonly class CanonicalLineageSummary
{
    public function __construct(
        public int $count,
        public string $hash,
        public int $firstVersion,
        public int $firstId,
        public int $lastVersion,
        public int $lastId,
    ) {
        if ($count < 1
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || min($firstVersion, $firstId, $lastVersion, $lastId) < 1
            || [$firstVersion, $firstId] > [$lastVersion, $lastId]
        ) {
            throw new InvalidArgumentException('canonical_lineage_summary_invalid');
        }
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== [
            'count',
            'first_id',
            'first_version',
            'hash',
            'last_id',
            'last_version',
        ]) {
            throw new InvalidArgumentException('canonical_lineage_summary_invalid');
        }

        return new self(
            count: (int) $value['count'],
            hash: (string) $value['hash'],
            firstVersion: (int) $value['first_version'],
            firstId: (int) $value['first_id'],
            lastVersion: (int) $value['last_version'],
            lastId: (int) $value['last_id'],
        );
    }

    public function canonicalIdentity(): array
    {
        return [
            'count' => $this->count,
            'first_id' => $this->firstId,
            'first_version' => $this->firstVersion,
            'hash' => $this->hash,
            'last_id' => $this->lastId,
            'last_version' => $this->lastVersion,
        ];
    }
}
