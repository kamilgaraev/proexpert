<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Generation;

use App\BusinessModules\Core\Reporting\Application\Generation\ReportCatalogArtifactGenerator;
use App\BusinessModules\Core\Reporting\Application\Generation\ReportPermissionTranslationGenerator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportCatalogResource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportCatalogArtifactGeneratorTest extends TestCase
{
    public function test_platform_generation_has_stable_group_order_and_no_manifest_ordinal_on_wire(): void
    {
        $generated = (new ReportCatalogArtifactGenerator)->generate('platform', $this->registry(), $this->inputs());

        self::assertSame('platform', $generated['catalog']['phase']);
        self::assertSame(['portfolio', 'projects', 'finance', 'procurement_warehouse', 'team', 'quality_safety', 'partners_customers'], $generated['catalog']['catalog_group_order']);
        self::assertSame('holding_performance', $generated['catalog']['definitions'][0]['code']);
        self::assertArrayNotHasKey('manifest_ordinal', $generated['resource']['definitions'][0]);
        self::assertStringContainsString("'holding_performance'", $generated['typeScript']);
        self::assertSame([
            'code', 'title_key', 'catalog_group', 'category', 'grain', 'wave', 'definition_hash', 'contract_version', 'formula_version', 'source_schema_version', 'renderer_version', 'filters', 'columns', 'sorts', 'formats', 'permission_policy', 'supports_subscriptions', 'reproducible_scheduled_snapshot', 'visibility',
        ], array_keys($generated['resource']['definitions'][0]));
    }

    public function test_release_generation_rejects_non_complete_catalog(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('release_catalog_count_invalid');

        (new ReportCatalogArtifactGenerator)->generate('release', $this->registry(), $this->inputs());
    }

    public function test_release_generation_requires_exact_set_and_every_group(): void
    {
        $generator = new ReportCatalogArtifactGenerator;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('release_catalog_group_empty');

        $generator->generate('release', $this->registryWithCount(28, false), $this->inputsForCodes(28, false));
    }

    public function test_generated_resource_is_the_runtime_resource_payload_and_lock_is_deterministic(): void
    {
        $generator = new ReportCatalogArtifactGenerator;
        $first = $generator->generate('platform', $this->registry(), $this->inputs());
        $second = $generator->generate('platform', $this->registry(), $this->inputs());
        $published = $this->registry()->published('holding_performance');
        $view = ReportCatalogDefinitionView::from(
            $published,
            new ReportCatalogMetadata('holding_performance', 'reports.catalog.holding_performance', ReportCatalogGroup::PORTFOLIO, 'portfolio', 'project', 1, 1),
            new ReportSchedulingCapability('holding_performance', false, false),
            new ReportVisibility(true, true, true, true, true, true, true),
        );

        self::assertSame(ReportCatalogResource::payload('1.0.0', $this->registry()->manifestSha256(), [$view]), $first['resource']);
        self::assertSame($first['lock'], $second['lock']);
        self::assertSame($first['lock']['resource_sha256'], hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($first['resource'])));
    }

    public function test_platform_serializes_empty_translation_maps_as_objects(): void
    {
        $registry = new class implements ReportDefinitionRegistry {
            public function published(string $code): PublishedReportDefinition { throw new \LogicException('not_called'); }
            public function publishedCodes(): array { return []; }
            public function manifestSha256(): Sha256Hash { return new Sha256Hash(hash('sha256', 'empty-manifest')); }
        };
        $inputs = $this->inputs();
        $inputs['manifest_bytes'] = 'empty-manifest';

        $generated = (new ReportCatalogArtifactGenerator)->generate('platform', $registry, $inputs);
        $decoded = json_decode(json_encode($generated['translations'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(\stdClass::class, $decoded->titles);
        self::assertInstanceOf(\stdClass::class, $decoded->permissions);
    }

    private function registry(): ReportDefinitionRegistry
    {
        $definition = new ReportDefinition(
            'holding_performance', new Sha256Hash(str_repeat('a', 64)), '1.0.0', '1.0.0', '1.0.0', '1.0.0',
            [['id' => 'project_id']], [['id' => 'amount']], [['id' => 'amount']], ['csv'],
            new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []),
            ReportSnapshotClassification::OPERATIONAL,
            new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false),
            ReportPublicationReadiness::PUBLISHED, false,
        );
        $published = new PublishedReportDefinition($definition);

        return new class($published) implements ReportDefinitionRegistry {
            public function __construct(private PublishedReportDefinition $published) {}
            public function published(string $code): PublishedReportDefinition { return $this->published; }
            public function publishedCodes(): array { return ['holding_performance']; }
            public function manifestSha256(): Sha256Hash { return new Sha256Hash(hash('sha256', 'manifest')); }
        };
    }

    private function inputs(): array
    {
        return [
            'manifest_bytes' => 'manifest',
            'metadata' => new class implements ReportCatalogMetadataRegistry {
                public function published(string $code): ReportCatalogMetadata { return new ReportCatalogMetadata($code, 'reports.catalog.'.$code, ReportCatalogGroup::PORTFOLIO, 'portfolio', 'project', 1, 1); }
            },
            'scheduling' => new class implements ReportSchedulingCapabilityRegistry {
                public function published(string $code): ReportSchedulingCapability { return new ReportSchedulingCapability($code, false, false); }
            },
            'translations' => ReportPermissionTranslationGenerator::fromProject(dirname(__DIR__, 4)),
        ];
    }

    private function registryWithCount(int $count, bool $distributeGroups): ReportDefinitionRegistry
    {
        $definitions = [];
        for ($index = 0; $index < $count; $index++) {
            $code = sprintf('report_%02d', $index);
            $definitions[$code] = new PublishedReportDefinition(new ReportDefinition(
                $code, new Sha256Hash(hash('sha256', $code)), '1.0.0', '1.0.0', '1.0.0', '1.0.0',
                [['id' => 'project_id']], [['id' => 'amount']], [['id' => 'amount']], ['csv'],
                new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []), ReportSnapshotClassification::OPERATIONAL,
                new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false), ReportPublicationReadiness::PUBLISHED, false,
            ));
        }

        return new class($definitions) implements ReportDefinitionRegistry {
            public function __construct(private array $definitions) {}
            public function published(string $code): PublishedReportDefinition { return $this->definitions[$code]; }
            public function publishedCodes(): array { return array_keys($this->definitions); }
            public function manifestSha256(): Sha256Hash { return new Sha256Hash(hash('sha256', 'release-manifest')); }
        };
    }

    private function inputsForCodes(int $count, bool $distributeGroups): array
    {
        $groups = ReportCatalogGroup::ordered();
        $titles = [];
        for ($index = 0; $index < $count; $index++) {
            $titles[sprintf('report_%02d', $index)] = 'Отчёт '.$index;
        }

        return [
            'manifest_bytes' => 'release-manifest',
            'metadata' => new class($groups, $distributeGroups) implements ReportCatalogMetadataRegistry {
                public function __construct(private array $groups, private bool $distributeGroups) {}
                public function published(string $code): ReportCatalogMetadata
                {
                    $ordinal = (int) substr($code, -2);
                    $group = $this->distributeGroups ? $this->groups[$ordinal % count($this->groups)] : ReportCatalogGroup::PORTFOLIO;
                    return new ReportCatalogMetadata($code, 'reports.catalog.'.$code, $group, 'catalog', 'project', 1, $ordinal);
                }
            },
            'scheduling' => new class implements ReportSchedulingCapabilityRegistry {
                public function published(string $code): ReportSchedulingCapability { return new ReportSchedulingCapability($code, false, false); }
            },
            'translations' => new ReportPermissionTranslationGenerator(
                ['catalog' => $titles, 'catalog_groups' => array_fill_keys(array_map(static fn (ReportCatalogGroup $group): string => $group->value, $groups), 'Группа')],
                ['values' => ['reports.view' => 'Просмотр', 'reports.export' => 'Экспорт']],
            ),
        ];
    }
}
