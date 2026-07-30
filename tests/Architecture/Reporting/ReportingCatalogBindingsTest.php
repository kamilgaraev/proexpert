<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingExecutionServiceProvider;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ReportingCatalogBindingsTest extends TestCase
{
    public function test_provider_is_registered_exactly_once_in_authoritative_order(): void
    {
        $providers = require dirname(__DIR__, 3).'/bootstrap/providers.php';
        $catalog = array_keys($providers, ReportingCatalogServiceProvider::class, true);
        $execution = array_search(ReportingExecutionServiceProvider::class, $providers, true);
        $contracts = array_search(ReportingContractsServiceProvider::class, $providers, true);

        self::assertCount(1, $catalog);
        self::assertSame($contracts + 1, $execution);
        self::assertSame($execution + 1, $catalog[0]);
    }

    public function test_provider_registers_all_authoritative_singletons(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingCatalogServiceProvider($app))->register();

        foreach ([
            ReportDefinitionRegistry::class,
            CandidateReportDefinitionRegistry::class,
            ReportDefinitionBindingAssembler::class,
            ReportDefinitionCandidateValidator::class,
            ReportDefinitionBindingMap::class,
        ] as $contract) {
            self::assertTrue($app->bound($contract), $contract);
            self::assertTrue($app->isShared($contract), $contract);
        }
    }

    public function test_empty_map_resolution_is_same_singleton_instance(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingCatalogServiceProvider($app))->register();
        $app->instance(ReportDefinitionRegistry::class, $this->emptyRegistry());

        $first = $app->make(ReportDefinitionBindingMap::class);
        $second = $app->make(ReportDefinitionBindingMap::class);
        $assemblerMap = $app
            ->make(ReportDefinitionBindingAssembler::class)
            ->assemble($app->make(ReportDefinitionRegistry::class));

        self::assertSame([], $first->all());
        self::assertSame($first, $second);
        self::assertSame($first, $assemblerMap);
    }

    private function emptyRegistry(): ReportDefinitionRegistry
    {
        return new class implements ReportDefinitionRegistry
        {
            public function published(string $code): PublishedReportDefinition
            {
                throw new \LogicException('unexpected_published_lookup');
            }

            public function publishedCodes(): array
            {
                return [];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('0', 64));
            }
        };
    }
}
