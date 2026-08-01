<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Catalog\GetReportCatalogHandler;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class GetReportCatalogHandlerTest extends TestCase
{
    public function test_catalog_orders_by_group_then_manifest_ordinal_independently_of_registry_order(): void
    {
        [$handler, $context, $authorization] = $this->subject(['accepted_production_progress', 'holding_performance', 'project_portfolio_health']);

        $catalog = $handler->handle($context, $authorization);

        self::assertSame(['project_portfolio_health', 'holding_performance', 'accepted_production_progress'], array_column($catalog->definitions, 'code'));
        self::assertSame(['portfolio', 'portfolio', 'projects'], array_map(static fn ($definition): string => $definition->catalogGroup->value, $catalog->definitions));
    }

    public function test_catalog_propagates_established_authorization_visibility_and_omits_only_unauthorized_definition(): void
    {
        [$handler, $context, $authorization] = $this->subject(['holding_performance', 'project_portfolio_health'], ['holding_performance']);

        $catalog = $handler->handle($context, $authorization);

        self::assertSame(['holding_performance'], array_column($catalog->definitions, 'code'));
        self::assertFalse($catalog->definitions[0]->visibility->canExport);
        $parameters = (new \ReflectionClass(GetReportCatalogHandler::class))->getConstructor()->getParameters();
        self::assertSame(3, count($parameters));
        self::assertNotContains('CandidateReportDefinitionRegistry', array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getType()?->getName() ?? '', $parameters));
    }

    /** @return array{GetReportCatalogHandler,ReportExecutionContext,ReportCatalogAuthorization} */
    private function subject(array $registryOrder, ?array $authorizedCodes = null): array
    {
        $metadata = [
            'project_portfolio_health' => [ReportCatalogGroup::PORTFOLIO, 0],
            'holding_performance' => [ReportCatalogGroup::PORTFOLIO, 1],
            'accepted_production_progress' => [ReportCatalogGroup::PROJECTS, 7],
        ];
        $published = [];
        foreach ($metadata as $code => [$group, $ordinal]) {
            $published[$code] = (new ReportDefinitionBuilder)
                ->code($code)
                ->definitionHash(new Sha256Hash(hash('sha256', $code)))
                ->published();
        }
        $registry = new class($published, $registryOrder) implements ReportDefinitionRegistry {
            public function __construct(private array $published, private array $order) {}
            public function published(string $code): PublishedReportDefinition { return $this->published[$code]; }
            public function publishedCodes(): array { return $this->order; }
            public function manifestSha256(): Sha256Hash { return new Sha256Hash(str_repeat('f', 64)); }
        };
        $metadataRegistry = new class($metadata) implements ReportCatalogMetadataRegistry {
            public function __construct(private array $metadata) {}
            public function published(string $code): ReportCatalogMetadata
            {
                [$group, $ordinal] = $this->metadata[$code];
                return new ReportCatalogMetadata($code, 'reports.catalog.'.$code, $group, $group->value, 'project', 1, $ordinal);
            }
        };
        $scheduling = new class implements ReportSchedulingCapabilityRegistry {
            public function published(string $code): ReportSchedulingCapability { return new ReportSchedulingCapability($code, false, false); }
        };
        $handler = new GetReportCatalogHandler($registry, $metadataRegistry, $scheduling);
        $actor = new ReportActor(17, 'active', []);
        $scope = new ReportScope(41, [41], [], [], new DateTimeZone('UTC'));
        $decision = new AuthorizationDecisionContext('http', 41, [41], [], [], new DateTimeZone('UTC'), 'catalog-test', null);
        $context = new ReportExecutionContext($actor, $scope, new ReportVisibility(true, true, true, true, true, true, true), $decision);
        $authorizedCodes ??= array_keys($published);
        $entries = [];
        foreach ($authorizedCodes as $code) {
            $visibility = new ReportVisibility(true, true, $code !== 'holding_performance', false, false, false, false);
            $target = new CurrentReportAuthorizationTarget($published[$code]->payload(), ReportOperation::VIEW, null);
            $entries[$published[$code]->definitionHash->value] = new CurrentReportAuthorization($actor, $decision, $visibility, $target);
        }

        return [$handler, $context, new ReportCatalogAuthorization($context, $entries)];
    }
}
