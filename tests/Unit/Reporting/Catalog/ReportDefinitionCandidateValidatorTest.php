<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportConformanceFixtureHashRegistry;
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
use Tests\Support\Reporting\ReportConformanceFixtureBuilder;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportDefinitionCandidateValidatorTest extends TestCase
{
    public function test_validates_exact_set_in_candidate_order_with_real_fixture_identities(): void
    {
        $first = (new ReportDefinitionBuilder)->code('zeta_report')->candidate();
        $second = (new ReportDefinitionBuilder)->code('alpha_report')->candidate();
        $firstBinding = CatalogBindingTestFactory::binding($first->payload());
        $secondBinding = CatalogBindingTestFactory::binding($second->payload());
        $firstFixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('4', 64)))
            ->build();
        $secondFixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('5', 64)))
            ->build();
        $repository = new RecordingReportConformanceEvidenceRepository([
            CatalogBindingTestFactory::evidence(
                $first->payload(),
                $firstBinding,
                $firstFixture->fixtureHash,
            ),
            CatalogBindingTestFactory::evidence(
                $second->payload(),
                $secondBinding,
                $secondFixture->fixtureHash,
            ),
        ]);

        $result = $this->validator(
            $repository,
            [
                $first->code => $firstFixture,
                $second->code => $secondFixture,
            ],
        )->validate(
            $this->registry([$first, $second]),
            [$secondBinding, $firstBinding],
        );

        self::assertSame(['zeta_report', 'alpha_report'], array_column($result->items, 'code'));
        self::assertTrue($result->passed());
        self::assertSame($firstFixture->fixtureHash, $repository->gets[0][2]);
        self::assertSame($secondFixture->fixtureHash, $repository->gets[1][2]);
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

    public function test_duplicate_and_wrong_type_bindings_fail_before_set_comparison(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());

        foreach ([
            [[$binding, $binding], 'candidate_binding_duplicate'],
            [[new \stdClass], 'candidate_binding_type_invalid'],
        ] as [$bindings, $message]) {
            try {
                $this->validatorFor($candidate, $binding)->validate(
                    $this->registry([$candidate]),
                    $bindings,
                );
                self::fail('Invalid binding set was accepted.');
            } catch (LogicException|InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_missing_fixture_identity_fails_closed_before_evidence_lookup(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $repository = new RecordingReportConformanceEvidenceRepository(
            CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                new Sha256Hash(str_repeat('7', 64)),
            ),
        );

        try {
            $this->validator($repository, [])->validate(
                $this->registry([$candidate]),
                [$binding],
            );
            self::fail('Missing fixture identity was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('report_conformance_fixture_not_found', $exception->getMessage());
            self::assertSame([], $repository->gets);
        }
    }

    public function test_each_binding_identity_mismatch_is_reported_independently(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $fixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('8', 64)))
            ->build();
        $cases = [
            'BINDING_CODE_MISMATCH' => CatalogBindingTestFactory::binding(
                $candidate->payload(),
                code: 'different_report',
            ),
            'BINDING_DEFINITION_HASH_MISMATCH' => CatalogBindingTestFactory::binding(
                $candidate->payload(),
                definitionHash: new Sha256Hash(str_repeat('9', 64)),
            ),
            'BINDING_CONTRACT_VERSION_MISMATCH' => CatalogBindingTestFactory::binding(
                $candidate->payload(),
                contractVersion: '2.0.0',
            ),
        ];

        foreach ($cases as $expected => $binding) {
            $checker = new ReportBindingCompatibilityChecker;
            $evidence = CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                $fixture->fixtureHash,
            );
            $item = $checker->candidate(
                $candidate,
                $binding,
                $evidence,
                $fixture->fixtureHash,
            );

            self::assertFalse($item->passed, $expected);
            self::assertSame([$expected], $item->failureCodes, $expected);
        }
    }

    public function test_each_evidence_identity_mismatch_is_reported_independently(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $fixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('a', 64)))
            ->build();
        $cases = [
            'EVIDENCE_CODE_MISMATCH' => CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                $fixture->fixtureHash,
                code: 'different_report',
            ),
            'EVIDENCE_DEFINITION_HASH_MISMATCH' => CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                $fixture->fixtureHash,
                definitionHash: new Sha256Hash(str_repeat('b', 64)),
            ),
            'EVIDENCE_CONTRACT_VERSION_MISMATCH' => CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                $fixture->fixtureHash,
                contractVersion: '2.0.0',
            ),
            'EVIDENCE_FIXTURE_HASH_MISMATCH' => CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                new Sha256Hash(str_repeat('c', 64)),
            ),
        ];

        foreach ($cases as $expected => $evidence) {
            $item = (new ReportBindingCompatibilityChecker)->candidate(
                $candidate,
                $binding,
                $evidence,
                $fixture->fixtureHash,
            );

            self::assertFalse($item->passed, $expected);
            self::assertSame([$expected], $item->failureCodes, $expected);
        }
    }

    public function test_each_bound_provider_hash_is_checked_independently(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $fixtureHash = new Sha256Hash(str_repeat('d', 64));
        $valid = CatalogBindingTestFactory::evidence(
            $candidate->payload(),
            $binding,
            $fixtureHash,
        )->componentClassHashes;

        foreach ([
            $binding->dataProvider::class,
            $binding->rowQuery::class,
            $binding->drillDownProvider::class,
        ] as $providerClass) {
            $hashes = $valid;
            $hashes[$providerClass] = new Sha256Hash(str_repeat('0', 64));
            $item = (new ReportBindingCompatibilityChecker)->candidate(
                $candidate,
                $binding,
                CatalogBindingTestFactory::evidence(
                    $candidate->payload(),
                    $binding,
                    $fixtureHash,
                    componentHashes: $hashes,
                ),
                $fixtureHash,
            );

            self::assertFalse($item->passed, $providerClass);
            self::assertSame(['EVIDENCE_PROVIDER_HASH_MISMATCH'], $item->failureCodes, $providerClass);
        }
    }

    public function test_failed_evidence_is_rejected_independently(): void
    {
        $candidate = (new ReportDefinitionBuilder)->code('candidate_report')->candidate();
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $fixtureHash = new Sha256Hash(str_repeat('e', 64));
        $item = (new ReportBindingCompatibilityChecker)->candidate(
            $candidate,
            $binding,
            CatalogBindingTestFactory::evidence(
                $candidate->payload(),
                $binding,
                $fixtureHash,
                false,
            ),
            $fixtureHash,
        );

        self::assertFalse($item->passed);
        self::assertSame(['EVIDENCE_NOT_PASSED'], $item->failureCodes);
    }

    private function validatorFor(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
    ): StrictReportDefinitionCandidateValidator {
        $fixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('f', 64)))
            ->build();

        return $this->validator(
            new RecordingReportConformanceEvidenceRepository(
                CatalogBindingTestFactory::evidence(
                    $candidate->payload(),
                    $binding,
                    $fixture->fixtureHash,
                ),
            ),
            [$candidate->code => $fixture],
        );
    }

    private function validator(
        RecordingReportConformanceEvidenceRepository $repository,
        array $fixtures,
    ): StrictReportDefinitionCandidateValidator {
        return new StrictReportDefinitionCandidateValidator(
            $repository,
            new ImmutableReportConformanceFixtureHashRegistry($fixtures),
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
