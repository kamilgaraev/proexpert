<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use HashContext;
use InvalidArgumentException;
use LogicException;

final class CanonicalLineageAccumulator
{
    private HashContext $hashContext;

    private int $count = 0;

    private ?int $firstVersion = null;

    private ?int $firstId = null;

    private ?int $lastVersion = null;

    private ?int $lastId = null;

    private bool $finished = false;

    public function __construct()
    {
        $this->hashContext = hash_init('sha256');
        hash_update($this->hashContext, '[');
    }

    public function append(int $version, int $id, array $identity): void
    {
        if ($this->finished
            || min($version, $id) < 1
            || ($this->lastVersion !== null && [$version, $id] <= [$this->lastVersion, $this->lastId])
        ) {
            throw new InvalidArgumentException('canonical_lineage_order_invalid');
        }

        if ($this->count > 0) {
            hash_update($this->hashContext, ',');
        }
        hash_update($this->hashContext, CanonicalJson::encode($identity));
        $this->firstVersion ??= $version;
        $this->firstId ??= $id;
        $this->lastVersion = $version;
        $this->lastId = $id;
        $this->count++;
    }

    public function finish(): CanonicalLineageSummary
    {
        if ($this->finished || $this->count < 1) {
            throw new LogicException('canonical_lineage_finish_invalid');
        }
        $this->finished = true;
        hash_update($this->hashContext, ']');

        return new CanonicalLineageSummary(
            count: $this->count,
            hash: hash_final($this->hashContext),
            firstVersion: $this->firstVersion
                ?? throw new LogicException('canonical_lineage_finish_invalid'),
            firstId: $this->firstId
                ?? throw new LogicException('canonical_lineage_finish_invalid'),
            lastVersion: $this->lastVersion
                ?? throw new LogicException('canonical_lineage_finish_invalid'),
            lastId: $this->lastId
                ?? throw new LogicException('canonical_lineage_finish_invalid'),
        );
    }
}
