<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class Sha256Hash
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('sha256_invalid');
        }
    }
}
