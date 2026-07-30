<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use TypeError;

final class CandidatePublishedBoundaryTest extends TestCase
{
    public function test_nominal_registry_return_types_are_distinct(): void
    {
        self::assertSame(
            CandidateReportDefinition::class,
            (new ReflectionMethod(CandidateReportDefinitionRegistry::class, 'candidate'))
                ->getReturnType()?->getName(),
        );
        self::assertSame(
            PublishedReportDefinition::class,
            (new ReflectionMethod(ReportDefinitionRegistry::class, 'published'))
                ->getReturnType()?->getName(),
        );
    }

    public function test_combined_registry_cannot_return_candidate_from_published_method(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_code')->candidate();
        $registry = new class($candidate) implements CandidateReportDefinitionRegistry, ReportDefinitionRegistry
        {
            public function __construct(
                private readonly CandidateReportDefinition $definition,
            ) {}

            public function published(string $code): PublishedReportDefinition
            {
                return $this->candidate($code);
            }

            public function publishedCodes(): array
            {
                return [$this->definition->code];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('a', 64));
            }

            public function candidate(string $code): CandidateReportDefinition
            {
                if ($this->definition->code !== $code) {
                    throw new \LogicException('unexpected_candidate_code');
                }

                return $this->definition;
            }

            public function candidateCodes(): array
            {
                return [$this->definition->code];
            }
        };

        $this->expectException(TypeError::class);
        $registry->published('candidate_code');
    }

    public function test_candidate_validator_returns_result_without_binding_map(): void
    {
        $method = new ReflectionMethod(StrictReportDefinitionCandidateValidator::class, 'validate');

        self::assertSame(ReportCandidateValidationResult::class, $method->getReturnType()?->getName());
        self::assertNotSame(ReportDefinitionBindingMap::class, $method->getReturnType()?->getName());
        self::assertStringNotContainsString(
            'ReportDefinitionBindingMap',
            (string) file_get_contents($method->getFileName()),
        );
    }
}
