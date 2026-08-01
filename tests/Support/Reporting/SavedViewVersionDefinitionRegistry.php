<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final class SavedViewVersionDefinitionRegistry implements ReportDefinitionRegistry
{
    public int $publishedCalls = 0;

    public function __construct(private readonly PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        $this->publishedCalls++;
        if ($code !== $this->definition->code) {
            throw new InvalidArgumentException('report_definition_not_found');
        }

        return $this->definition;
    }

    public function publishedCodes(): array
    {
        return [$this->definition->code];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('f', 64));
    }
}
