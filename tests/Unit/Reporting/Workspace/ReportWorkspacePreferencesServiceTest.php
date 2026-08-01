<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Workspace;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Workspace\ReportWorkspacePreferencesService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspaceDisplayPreferences;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportWorkspacePreferencesServiceTest extends TestCase
{
    public function test_eleventh_recent_evicts_oldest_without_crossing_tenant(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions($this->codes(11)));

        foreach ($this->codes(11) as $code) {
            $result = $service->recordRecent($this->context(), $code);
        }

        self::assertCount(10, $result->recentReportCodes);
        self::assertSame('report_11', $result->recentReportCodes[0]);
        self::assertNotContains('report_01', $result->recentReportCodes);
        self::assertSame([], $service->get($this->context(['reports.manage', 'reports.view'], 2, 2))->recentReportCodes);
    }

    public function test_recent_reorders_an_existing_code_without_duplication(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions(['report_one', 'report_two']));

        $service->recordRecent($this->context(), 'report_one');
        $result = $service->recordRecent($this->context(), 'report_two');
        $result = $service->recordRecent($this->context(), 'report_one');

        self::assertSame(['report_one', 'report_two'], $result->recentReportCodes);
    }

    public function test_favourites_are_deduplicated_in_request_order(): void
    {
        $service = $this->service(new InMemoryReportWorkspacePreferencesStore, $this->definitions(['report_one', 'report_two', 'report_three']));

        $result = $service->setFavourites($this->context(), ['report_two', 'report_one', 'report_two', 'report_three']);

        self::assertSame(['report_two', 'report_one', 'report_three'], $result->favouriteReportCodes);
    }

    public function test_nonpublished_code_fails_without_storage_mutation(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions([]));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->recordRecent($this->context(), 'missing_report');
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function test_invalid_group_permutation_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportWorkspaceDisplayPreferences(
            ['portfolio', 'projects', 'finance', 'procurement_warehouse', 'team', 'quality_safety', 'quality_safety'],
            [],
            'catalog',
        );
    }

    public function test_get_returns_exact_defaults_without_persisting_them(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $result = $this->service($store, $this->definitions([]))->get($this->context());

        self::assertSame([], $result->recentReportCodes);
        self::assertSame([], $result->favouriteReportCodes);
        self::assertSame('catalog', $result->display->landingSection);
        self::assertSame([], $store->writes);
    }

    public function test_default_display_uses_all_seven_catalog_groups_in_task_order(): void
    {
        self::assertSame([
            'portfolio', 'projects', 'finance', 'procurement_warehouse', 'team', 'quality_safety', 'partners_customers',
        ], ReportWorkspaceDisplayPreferences::defaults()->catalogGroupOrder);
    }

    public function test_collapsed_groups_must_be_unique_subset_of_catalog_groups(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportWorkspaceDisplayPreferences(
            ReportWorkspaceDisplayPreferences::defaults()->catalogGroupOrder,
            ['projects', 'projects'],
            'catalog',
        );
    }

    public function test_landing_section_is_closed_enum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportWorkspaceDisplayPreferences(
            ReportWorkspaceDisplayPreferences::defaults()->catalogGroupOrder,
            [],
            'unknown',
        );
    }

    public function test_revoked_definition_permission_rejects_mutation_before_storage_write(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $definition = (new ReportDefinitionBuilder)
            ->code('report_one')
            ->permissionPolicy(new ReportPermissionPolicy(['definition.view'], ['reports.export'], [], []))
            ->published();
        $registry = new class($definition) implements ReportDefinitionRegistry
        {
            public function __construct(private PublishedReportDefinition $definition) {}

            public function published(string $code): PublishedReportDefinition
            {
                return $this->definition;
            }

            public function publishedCodes(): array
            {
                return [$this->definition->code];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('a', 64));
            }
        };

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        try {
            $this->service($store, $registry)->recordRecent($this->context(), 'report_one');
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function test_nonpublished_favourite_does_not_replace_existing_favourites(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions(['report_one']));
        $service->setFavourites($this->context(), ['report_one']);

        try {
            $service->setFavourites($this->context(), ['missing_report']);
            self::fail('Expected missing report to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(['report_one'], $service->get($this->context())->favouriteReportCodes);
        }
    }

    public function test_missing_manage_permission_rejects_recent_mutation(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions(['report_one']), ['reports.view']);

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        try {
            $service->recordRecent($this->context(['reports.view']), 'report_one');
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function test_display_update_preserves_report_lists(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions(['report_one', 'report_two']));
        $service->recordRecent($this->context(), 'report_one');
        $service->setFavourites($this->context(), ['report_two']);

        $result = $service->updateDisplay(
            $this->context(),
            new ReportWorkspaceDisplayPreferences(
                array_reverse(ReportWorkspaceDisplayPreferences::defaults()->catalogGroupOrder),
                ['projects'],
                'favourites',
            ),
        );

        self::assertSame(['report_one'], $result->recentReportCodes);
        self::assertSame(['report_two'], $result->favouriteReportCodes);
        self::assertSame('favourites', $result->display->landingSection);
    }

    public function test_get_rejects_nonpublished_code_already_in_workspace(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $store->updateLocked(1, 1, static fn (ReportWorkspacePreferences $current): ReportWorkspacePreferences => new ReportWorkspacePreferences(
            ['missing_report'], [], $current->display, $current->updatedAt,
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->service($store, $this->definitions([]))->get($this->context());
    }

    public function test_workspace_rows_are_isolated_by_owner_within_one_organization(): void
    {
        $store = new InMemoryReportWorkspacePreferencesStore;
        $service = $this->service($store, $this->definitions(['report_one']));
        $service->recordRecent($this->context(), 'report_one');

        self::assertSame([], $service->get($this->context(['reports.manage', 'reports.view'], 1, 2))->recentReportCodes);
    }

    public function test_workspace_preference_dto_rejects_more_than_ten_recent_reports(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportWorkspacePreferences(
            $this->codes(11),
            [],
            ReportWorkspaceDisplayPreferences::defaults(),
            new DateTimeImmutable('now'),
        );
    }

    public function test_workspace_preference_dto_rejects_duplicate_favourites(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportWorkspacePreferences(
            [],
            ['report_one', 'report_one'],
            ReportWorkspaceDisplayPreferences::defaults(),
            new DateTimeImmutable('now'),
        );
    }

    private function service(InMemoryReportWorkspacePreferencesStore $store, ReportDefinitionRegistry $definitions, array $permissions = ['reports.manage', 'reports.view']): ReportWorkspacePreferencesService
    {
        $loader = new class($permissions) implements ReportActorLoader
        {
            public function __construct(private array $permissions) {}

            public function loadActive(int $actorId): ReportActor
            {
                return new ReportActor($actorId, 'active', $this->permissions);
            }
        };
        $resolver = new class implements ReportSourceAccessResolver
        {
            public function assertAccessible(ReportExecutionContext $context, ReportDefinition $definition, ReportSourceRef $source): void {}
        };

        return new ReportWorkspacePreferencesService($store, $definitions, new ReportAccessService(
            $loader,
            $resolver,
            new ReportDefinitionVisibilityResolver(
                new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement),
            ),
        ));
    }

    private function definitions(array $codes): ReportDefinitionRegistry
    {
        $definitions = [];
        foreach ($codes as $index => $code) {
            $definitions[$code] = (new ReportDefinitionBuilder)
                ->code($code)
                ->definitionHash(new Sha256Hash(str_pad(dechex($index + 1), 64, '0', STR_PAD_LEFT)))
                ->published();
        }

        return new class($definitions) implements ReportDefinitionRegistry
        {
            public function __construct(private array $definitions) {}

            public function published(string $code): PublishedReportDefinition
            {
                if (! isset($this->definitions[$code])) {
                    throw new \InvalidArgumentException('report_not_published');
                }

                return $this->definitions[$code];
            }

            public function publishedCodes(): array
            {
                return array_keys($this->definitions);
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('a', 64));
            }
        };
    }

    private function context(array $permissions = ['reports.manage', 'reports.view'], int $organizationId = 1, int $actorId = 1): ReportExecutionContext
    {
        if ($organizationId === 1 && $actorId === 1) {
            return (new ReportExecutionContextBuilder)->actor(new ReportActor(1, 'active', $permissions))->build();
        }

        $timezone = new \DateTimeZone('UTC');

        return new ReportExecutionContext(
            new ReportActor($actorId, 'active', $permissions),
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope($organizationId, [$organizationId], [], [], $timezone),
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility(true, false, false, false, true, false, false),
            new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext('http', $organizationId, [$organizationId], [], [], $timezone, 'workspace-test', null),
        );
    }

    private function codes(int $count): array
    {
        return array_map(static fn (int $number): string => sprintf('report_%02d', $number), range(1, $count));
    }
}

final class InMemoryReportWorkspacePreferencesStore implements ReportWorkspacePreferencesStore
{
    /** @var array<string, ReportWorkspacePreferences> */
    private array $preferences = [];

    public array $writes = [];

    public function get(int $organizationId, int $ownerId): ?ReportWorkspacePreferences
    {
        return $this->preferences["{$organizationId}:{$ownerId}"] ?? null;
    }

    public function updateLocked(int $organizationId, int $ownerId, Closure $change): ReportWorkspacePreferences
    {
        $key = "{$organizationId}:{$ownerId}";
        $next = $change($this->preferences[$key] ?? ReportWorkspacePreferences::defaults());
        $this->preferences[$key] = new ReportWorkspacePreferences(
            $next->recentReportCodes,
            $next->favouriteReportCodes,
            $next->display,
            new DateTimeImmutable('now'),
        );
        $this->writes[] = $key;

        return $this->preferences[$key];
    }
}
