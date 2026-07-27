<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final class FakeReportReadinessProbe implements ReportDefinitionReadinessProbe
{
    private array $definitions = [];

    public function __construct(private readonly bool $supported) {}

    public function supports(ReportDefinition $definition): bool
    {
        $this->definitions[] = $definition;

        return $this->supported;
    }

    public function definitions(): array
    {
        return $this->definitions;
    }
}
