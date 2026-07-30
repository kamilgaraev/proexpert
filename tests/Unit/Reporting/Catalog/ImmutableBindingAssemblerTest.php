<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportBindingCompatibilityChecker;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\FakeReportReadinessProbe;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ImmutableBindingAssemblerTest extends TestCase
{
    public function test_empty_published_and_registered_sets_are_deployable(): void
    {
        $map = $this->assembler()->assemble($this->registry([]));

        self::assertSame([], $map->all());
    }

    public function test_missing_and_extra_registered_bindings_fail_exact_set(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();

        foreach ([[], ['published_report', 'extra_report']] as $bindingCodes) {
            $assembler = $this->assemblerWithCodes($bindingCodes);
            try {
                $assembler->assemble($this->registry([$published]));
                self::fail('Exact published set mismatch was accepted.');
            } catch (LogicException $exception) {
                self::assertSame('published_binding_set_mismatch', $exception->getMessage());
            }
        }
    }

    public function test_non_lexicographic_published_order_is_preserved(): void
    {
        $codes = ['project_portfolio_health', 'holding_performance', 'accepted_production_progress'];
        $definitions = array_map(
            static fn (string $code): PublishedReportDefinition => (new ReportDefinitionBuilder)
                ->code($code)
                ->published(),
            $codes,
        );
        $assembler = $this->assembler();
        foreach (array_reverse($definitions) as $published) {
            $assembler->register(CatalogBindingTestFactory::binding($published->payload()));
        }

        self::assertSame(
            $codes,
            array_keys($assembler->assemble($this->registry($definitions))->all()),
        );
    }

    public function test_duplicate_registry_codes_fail_before_set_comparison(): void
    {
        $published = (new ReportDefinitionBuilder)->code('one_report')->published();
        $assembler = $this->assemblerWithCodes(['one_report']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('published_code_duplicate');

        $assembler->assemble($this->registry([$published], ['one_report', 'one_report']));
    }

    public function test_wrong_type_registry_code_fails_before_set_comparison(): void
    {
        $published = (new ReportDefinitionBuilder)->code('one_report')->published();
        $assembler = $this->assemblerWithCodes(['one_report']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('published_code_invalid');

        $assembler->assemble($this->registry([$published], ['one_report', 42]));
    }

    public function test_failed_assembly_keeps_registration_open(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();
        $assembler = $this->assembler();

        try {
            $assembler->assemble($this->registry([$published]));
            self::fail('Missing binding was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('published_binding_set_mismatch', $exception->getMessage());
        }

        $assembler->register(CatalogBindingTestFactory::binding($published->payload()));
        self::assertSame(
            $published->code,
            $assembler->assemble($this->registry([$published]))->get($published->code)->code,
        );
    }

    public function test_success_freezes_registration_and_subsequent_assembly(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();
        $assembler = $this->assemblerWithCodes([$published->code]);
        $registry = $this->registry([$published]);
        $map = $assembler->assemble($registry);

        self::assertSame($published->code, $map->get($published->code)->code);
        try {
            $assembler->register(CatalogBindingTestFactory::binding($published->payload()));
            self::fail('Frozen assembler accepted registration.');
        } catch (LogicException $exception) {
            self::assertSame('binding_registration_closed', $exception->getMessage());
        }
        try {
            $assembler->assemble($registry);
            self::fail('Frozen assembler accepted second assembly.');
        } catch (LogicException $exception) {
            self::assertSame('binding_assembly_closed', $exception->getMessage());
        }
    }

    public function test_each_runtime_identity_and_readiness_failure_is_independent(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();
        $cases = [
            'code' => [
                CatalogBindingTestFactory::binding($published->payload(), code: 'different_report'),
                'published_binding_incompatible',
            ],
            'hash' => [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    definitionHash: new Sha256Hash(str_repeat('b', 64)),
                ),
                'published_binding_incompatible',
            ],
            'version' => [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    contractVersion: '2.0.0',
                ),
                'published_binding_incompatible',
            ],
            'readiness' => [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    new FakeReportReadinessProbe(false),
                ),
                'published_binding_not_ready',
            ],
        ];

        foreach ($cases as $case => [$binding, $message]) {
            try {
                (new ReportBindingCompatibilityChecker)->runtime($published, $binding);
                self::fail("Runtime mismatch {$case} was accepted.");
            } catch (LogicException $exception) {
                self::assertSame($message, $exception->getMessage(), $case);
            }
        }
    }

    public function test_compatibility_and_readiness_failures_leave_registration_open(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();
        $cases = [
            [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    definitionHash: new Sha256Hash(str_repeat('b', 64)),
                ),
                'published_binding_incompatible',
            ],
            [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    contractVersion: '2.0.0',
                ),
                'published_binding_incompatible',
            ],
            [
                CatalogBindingTestFactory::binding(
                    $published->payload(),
                    new FakeReportReadinessProbe(false),
                ),
                'published_binding_not_ready',
            ],
        ];

        foreach ($cases as $index => [$binding, $expectedMessage]) {
            $assembler = $this->assembler();
            $assembler->register($binding);
            try {
                $assembler->assemble($this->registry([$published]));
                self::fail('Incompatible binding was accepted.');
            } catch (LogicException $exception) {
                self::assertSame($expectedMessage, $exception->getMessage());
                $extra = (new ReportDefinitionBuilder)
                    ->code('extra_report_'.($index + 1))
                    ->published();
                $assembler->register(CatalogBindingTestFactory::binding($extra->payload()));
            }
        }
    }

    public function test_runtime_accepts_release_exact_28_with_ready_bindings(): void
    {
        $definitions = [];
        $assembler = $this->assembler();
        for ($index = 1; $index <= 28; $index++) {
            $published = (new ReportDefinitionBuilder)
                ->code(sprintf('release_report_%02d', $index))
                ->definitionHash(new Sha256Hash(hash('sha256', (string) $index)))
                ->published();
            $definitions[] = $published;
            $assembler->register(CatalogBindingTestFactory::binding(
                $published->payload(),
                new FakeReportReadinessProbe(true),
            ));
        }

        self::assertCount(28, $assembler->assemble($this->registry($definitions))->all());
    }

    private function assembler(): ImmutableReportDefinitionBindingAssembler
    {
        return new ImmutableReportDefinitionBindingAssembler(
            new ReportBindingCompatibilityChecker,
            new ReportCodeSetComparator,
        );
    }

    private function assemblerWithCodes(array $codes): ImmutableReportDefinitionBindingAssembler
    {
        $assembler = $this->assembler();
        foreach ($codes as $code) {
            $definition = (new ReportDefinitionBuilder)->code($code)->published();
            $assembler->register(CatalogBindingTestFactory::binding($definition->payload()));
        }

        return $assembler;
    }

    private function registry(
        array $definitions,
        ?array $codes = null,
    ): ReportDefinitionRegistry {
        return new class($definitions, $codes) implements ReportDefinitionRegistry
        {
            private array $byCode = [];

            public function __construct(
                private array $definitions,
                private ?array $codes,
            ) {
                foreach ($definitions as $definition) {
                    $this->byCode[$definition->code] = $definition;
                }
            }

            public function published(string $code): PublishedReportDefinition
            {
                return $this->byCode[$code];
            }

            public function publishedCodes(): array
            {
                return $this->codes ?? array_column($this->definitions, 'code');
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('a', 64));
            }
        };
    }
}
