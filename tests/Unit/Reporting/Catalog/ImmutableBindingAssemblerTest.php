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

    public function test_success_freezes_registration_and_returns_same_map(): void
    {
        $published = (new ReportDefinitionBuilder)->code('published_report')->published();
        $assembler = $this->assemblerWithCodes([$published->code]);
        $registry = $this->registry([$published]);
        $first = $assembler->assemble($registry);

        self::assertSame($first, $assembler->assemble($registry));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('binding_registration_closed');
        $assembler->register(CatalogBindingTestFactory::binding($published->payload()));
    }

    public function test_runtime_checks_identity_readiness_and_release_exact_28(): void
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

        $notReady = (new ReportDefinitionBuilder)->code('not_ready_report')->published();
        $closed = $this->assembler();
        $closed->register(CatalogBindingTestFactory::binding(
            $notReady->payload(),
            new FakeReportReadinessProbe(false),
        ));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('published_binding_not_ready');
        $closed->assemble($this->registry([$notReady]));
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
