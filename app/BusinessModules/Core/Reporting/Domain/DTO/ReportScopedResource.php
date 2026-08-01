<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportScopedResource
{
    public function __construct(
        public string $kind,
        public int $id,
        public ?int $projectId,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $kind) !== 1
            || $id < 1
            || ($projectId !== null && $projectId < 1)) {
            throw new InvalidArgumentException('report_scoped_resource_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'project_id' => $this->projectId,
        ];
    }
}
