<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class JointQG14Evidence
{
    public function __construct(public int $adminForbiddenSymbolMatches, public int $backendForbiddenSymbolMatches, public int $combinedForbiddenSymbolMatches, public Sha256Hash $qg14AdminSha256, public Sha256Hash $qg14BackendSha256, public Sha256Hash $qg14CombinedSha256, public array $argv, public string $commandId)
    {
        if ($adminForbiddenSymbolMatches !== 0
            || $backendForbiddenSymbolMatches !== 0
            || $combinedForbiddenSymbolMatches !== $adminForbiddenSymbolMatches + $backendForbiddenSymbolMatches
            || $commandId !== 'qg14_forbidden_symbols'
            || $argv !== ['node', 'scripts/verify-reporting-cutover.mjs', $argv[2] ?? null, $argv[3] ?? null]
            || ! is_string($argv[2] ?? null)
            || ! is_string($argv[3] ?? null)
            || ! str_starts_with($argv[2], '--admin-root=')
            || ! str_starts_with($argv[3], '--backend-root=')
            || $argv[2] === '--admin-root='
            || $argv[3] === '--backend-root=') {
            throw new InvalidArgumentException('joint_qg14_evidence_invalid');
        }
    }
}
