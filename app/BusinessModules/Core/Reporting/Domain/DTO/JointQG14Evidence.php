<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class JointQG14Evidence
{
    public function __construct(public int $adminForbiddenSymbolMatches, public int $backendForbiddenSymbolMatches, public int $combinedForbiddenSymbolMatches, public Sha256Hash $qg14AdminSha256, public Sha256Hash $qg14BackendSha256, public Sha256Hash $qg14CombinedSha256, public array $argv, public string $commandId)
    {
        if ($adminForbiddenSymbolMatches !== 0 || $backendForbiddenSymbolMatches !== 0 || $combinedForbiddenSymbolMatches !== 0 || $commandId !== 'qg14_forbidden_symbols') {
            throw new InvalidArgumentException('joint_qg14_evidence_invalid');
        }
    }
}
