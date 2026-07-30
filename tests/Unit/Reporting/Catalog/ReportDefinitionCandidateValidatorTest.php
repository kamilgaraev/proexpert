<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportBindingCompatibilityChecker;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\RecordingReportConformanceEvidenceRepository;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportDefinitionCandidateValidatorTest extends TestCase
{
    public function test_validates_exact_set_in_candidate_order_and_exact_fixture_identity(): void
    {
        $first = (new ReportDefinitionBuilder)->code('zeta_report')->candidate();
        $second = (new ReportDefinitionBuilder)->code('alpha_report')->candidate();
        $firstBinding = CatalogBindingTestFactory::binding($first->payload());
        $secondBinding = CatalogBindingTestFactory::binding($second->payload());
        $repository = new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence($first->payload(), $firstBinding),
        );
        $validator = $this->validator($repository);

        $result = $validator->validate(
            $this->registry([$first, $second]),
            [$secondBinding, $firstBinding],
        );

        self::assertSame(['zeta_report', 'alpha_report'], array_column($result->items, 'code'));
        self::assertTrue($result->items[0]->passed);
        self::assertSame('zeta_report', $repository->gets[0][0]);
        self::assertSame(hash('sha256', 'zeta_report'), $repository->gets[0][2]->value);

        $wrongFixture = new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence(
                $first->payload(),
                $firstBinding,
                fixtureHash: new Sha256Hash(str_repeat('f', 64)),
            ),
        );
        $item = $this->validator($wrongFixture)->validate(
            $this->registry([$first]),
            [$firstBinding],
        )->items[0];
        self::assertFalse($item->passed);
        self::assertSame(['EVIDENCE_FIXTURE_HASH_MISMATCH'], $item->failureCodes);
    }

    public function test_missing_or_extra_binding_fails_exact_set(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $extra = (new ReportDefinitionBuilder)->code('extra_report')->candidate();

        foreach ([
            [],
            [$binding, CatalogBindingTestFactory::binding($extra->payload())],
        ] as $bindings) {
            try {
                $this->validatorFor($candidate, $binding)->validate(
                    $this->registry([$candidate]),
                    $bindings,
                );
                self::fail('Exact set mismatch was accepted.');
            } catch (LogicException $exception) {
                self::assertSame('candidate_binding_set_mismatch', $exception->getMessage());
            }
        }
    }

    public function test_duplicate_binding_is_rejected_before_set_comparison(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('candidate_binding_duplicate');

        $this->validatorFor($candidate, $binding)->validate(
            $this->registry([$candidate]),
            [$binding, $binding],
        );
    }

    public function test_wrong_binding_type_is_rejected_before_registry_access(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('candidate_binding_type_invalid');

        $this->validatorFor($candidate, $binding)->validate(
            $this->registry([$candidate]),
            [new \stdClass],
        );
    }

    public function test_candidate_compatibility_reports_identity_and_failed_evidence(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding(
            $candidate->payload(),
            definitionHash: new Sha256Hash(str_repeat('b', 64)),
            contractVersion: '2.0.0',
        );
        $repository = new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                false,
            ),
        );

        $item = $this->validator($repository)->validate(
            $this->registry([$candidate]),
            [$binding],
        )->items[0];

        self::assertFalse($item->passed);
        self::assertContains('BINDING_DEFINITION_HASH_MISMATCH', $item->failureCodes);
        self::assertContains('BINDING_CONTRACT_VERSION_MISMATCH', $item->failureCodes);
        self::assertContains('EVIDENCE_NOT_PASSED', $item->failureCodes);
    }

    public function test_candidate_requires_hashes_for_all_three_bound_providers(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $wrongHashes = [];
        foreach ([
            $binding->dataProvider::class,
            $binding->rowQuery::class,
            $binding->drillDownProvider::class,
        ] as $class) {
            $wrongHashes[$class] = new Sha256Hash(str_repeat('0', 64));
        }
        $repository = new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                componentHashes: $wrongHashes,
            ),
        );

        $item = $this->validator($repository)->validate(
            $this->registry([$candidate]),
            [$binding],
        )->items[0];

        self::assertFalse($item->passed);
        self::assertSame(['EVIDENCE_PROVIDER_HASH_MISMATCH'], $item->failureCodes);
    }

    private function validatorFor(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
    ): StrictReportDefinitionCandidateValidator {
        return $this->validator(new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence($candidate->payload(), $binding),
        ));
    }

    private function validator(
        RecordingReportConformanceEvidenceRepository $repository,
    ): StrictReportDefinitionCandidateValidator {
        return new StrictReportDefinitionCandidateValidator(
            $repository,
            new ReportBindingCompatibilityChecker,
            new ReportCodeSetComparator,
        );
    }

    private function registry(array $definitions): CandidateReportDefinitionRegistry
    {
        return new class($definitions) implements CandidateReportDefinitionRegistry
        {
            private array $byCode = [];

            public function __construct(private array $definitions)
            {
                foreach ($definitions as $definition) {
                    $this->byCode[$definition->code] = $definition;
                }
            }

            public function candidate(string $code): CandidateReportDefinition
            {
                return $this->byCode[$code];
            }

            public function candidateCodes(): array
            {
                return array_column($this->definitions, 'code');
            }
        };
    }
}
