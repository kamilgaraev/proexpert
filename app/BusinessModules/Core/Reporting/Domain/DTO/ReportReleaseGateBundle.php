<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportReleaseGateBundle
{
    public function __construct(public string $artifactId, public string $status, public string $releaseSha, public array $gates, public array $sources, public DateTimeImmutable $generatedAt)
    {
        if ($artifactId !== 'report_release_gate_bundle' || $status !== 'release_gates_passed' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || ! array_is_list($gates) || count($gates) !== 14) {
            throw new InvalidArgumentException('report_release_gate_bundle_invalid');
        }
    }
}
