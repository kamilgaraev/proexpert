<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use HashContext;

final class DesignCompletenessFingerprint
{
    private HashContext $context;

    private bool $firstDataset = true;

    private bool $firstRow = true;

    private bool $nullDataset = false;

    public function __construct()
    {
        $this->context = hash_init('sha256');
    }

    public function beginDataset(string $name, bool $null = false): void
    {
        hash_update($this->context, ($this->firstDataset ? '{' : ',').json_encode($name, JSON_THROW_ON_ERROR).':'.($null ? 'null' : '['));
        $this->firstDataset = false;
        $this->firstRow = true;
        $this->nullDataset = $null;
    }

    public function append(array $row): void
    {
        hash_update($this->context, ($this->firstRow ? '' : ',').json_encode($row, JSON_THROW_ON_ERROR, 510));
        $this->firstRow = false;
    }

    public function endDataset(): void
    {
        if (! $this->nullDataset) {
            hash_update($this->context, ']');
        }
    }

    public function finish(): string
    {
        hash_update($this->context, '}');

        return hash_final($this->context);
    }
}
