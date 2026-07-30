<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Generation;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportCatalogResource;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportCatalogArtifactGenerator
{
    /**
     * @param  array{manifest_bytes:string,metadata:ReportCatalogMetadataRegistry,scheduling:ReportSchedulingCapabilityRegistry,translations:ReportPermissionTranslationGenerator}  $inputs
     * @return array{catalog:array,typescript:string,translations:array,resource:array,lock:array}
     */
    public function generate(string $phase, ReportDefinitionRegistry $registry, array $inputs): array
    {
        if (! in_array($phase, ['platform', 'release'], true)) {
            throw new InvalidArgumentException('reporting_generation_phase_invalid');
        }

        $manifestBytes = $inputs['manifest_bytes'] ?? null;
        $metadata = $inputs['metadata'] ?? null;
        $scheduling = $inputs['scheduling'] ?? null;
        $translations = $inputs['translations'] ?? null;
        if (! is_string($manifestBytes)
            || ! $metadata instanceof ReportCatalogMetadataRegistry
            || ! $scheduling instanceof ReportSchedulingCapabilityRegistry
            || ! $translations instanceof ReportPermissionTranslationGenerator
            || ! hash_equals(hash('sha256', $manifestBytes), $registry->manifestSha256()->value)) {
            throw new InvalidArgumentException('reporting_generation_inputs_invalid');
        }

        $definitions = [];
        $resourceViews = [];
        foreach ($registry->publishedCodes() as $code) {
            $published = $registry->published($code);
            $definition = $published->payload();
            $itemMetadata = $metadata->published($code);
            $itemScheduling = $scheduling->published($code);
            $resourceViews[$code] = ReportCatalogDefinitionView::from(
                $published,
                $itemMetadata,
                $itemScheduling,
                new ReportVisibility(true, true, true, true, true, true, true),
            );
            $definitions[] = [
                'code' => $definition->code,
                'catalog_group' => $itemMetadata->catalogGroup->value,
                'category' => $itemMetadata->category,
                'definition_hash' => $definition->definitionHash->value,
                'versions' => [
                    'contract' => $definition->contractVersion,
                    'formula' => $definition->formulaVersion,
                    'source_schema' => $definition->sourceSchemaVersion,
                    'renderer' => $definition->rendererVersion,
                ],
                'permissions' => [
                    'view' => $definition->permissionPolicy->viewPermissions,
                    'export' => $definition->permissionPolicy->exportPermissions,
                    'sensitive' => $definition->permissionPolicy->sensitivePermissions,
                    'audit' => $definition->permissionPolicy->auditPermissions,
                ],
                'capabilities' => [
                    'filters' => $definition->filters,
                    'columns' => $definition->columns,
                    'sorts' => $definition->sorts,
                    'formats' => $definition->formats,
                    'supports_subscriptions' => $itemScheduling->supportsSubscriptions,
                    'reproducible_scheduled_snapshot' => $itemScheduling->reproducibleScheduledSnapshot,
                ],
                '_ordinal' => $itemMetadata->manifestOrdinal,
            ];
        }

        $groupOrder = array_map(static fn (ReportCatalogGroup $group): string => $group->value, ReportCatalogGroup::ordered());
        $groupRanks = array_flip($groupOrder);
        usort($definitions, static fn (array $left, array $right): int => [
            $groupRanks[$left['catalog_group']], $left['_ordinal'],
        ] <=> [
            $groupRanks[$right['catalog_group']], $right['_ordinal'],
        ]);

        $this->assertPhase($phase, $definitions, $groupOrder);
        foreach ($definitions as &$definition) {
            unset($definition['_ordinal']);
        }
        unset($definition);

        $catalog = [
            'contract_version' => '1.0.0',
            'phase' => $phase,
            'manifest_sha256' => $registry->manifestSha256()->value,
            'catalog_group_order' => $groupOrder,
            'definitions' => $definitions,
        ];
        $codes = array_column($definitions, 'code');
        $permissions = [];
        foreach ($definitions as $definition) {
            foreach ($definition['permissions'] as $items) {
                $permissions = [...$permissions, ...$items];
            }
        }
        $translationArtifact = $translations->generate($codes, $groupOrder, $permissions);
        $translationArtifact['titles'] = $translationArtifact['titles'] === []
            ? new \stdClass
            : $translationArtifact['titles'];
        $translationArtifact['permissions'] = $translationArtifact['permissions'] === []
            ? new \stdClass
            : $translationArtifact['permissions'];
        $resource = ReportCatalogResource::payload(
            '1.0.0',
            $registry->manifestSha256(),
            array_map(static fn (array $definition): ReportCatalogDefinitionView => $resourceViews[$definition['code']], $definitions),
        );
        $typeScript = $this->typeScript($groupOrder, $codes);
        $lock = [
            'contract_version' => '1.0.0',
            'phase' => $phase,
            'manifest_sha256' => $registry->manifestSha256()->value,
            'resource_sha256' => hash('sha256', CanonicalJson::encode($resource)),
            'permission_sha256' => hash('sha256', CanonicalJson::encode($catalog['definitions'])),
            'translation_sha256' => hash('sha256', json_encode($translationArtifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'group_order_sha256' => hash('sha256', CanonicalJson::encode(['catalog_group_order' => $groupOrder])),
            'published_count' => count($definitions),
        ];

        return [
            'catalog' => $catalog,
            'typeScript' => $typeScript,
            'translations' => $translationArtifact,
            'resource' => $resource,
            'lock' => $lock,
        ];
    }

    /** @param list<array<string,mixed>> $definitions @param list<string> $groupOrder */
    private function assertPhase(string $phase, array $definitions, array $groupOrder): void
    {
        $count = count($definitions);
        if ($phase === 'platform' && ($count < 0 || $count > 28)) {
            throw new InvalidArgumentException('platform_catalog_count_invalid');
        }
        if ($phase !== 'release') {
            return;
        }
        if ($count !== 28 || count(array_unique(array_column($definitions, 'code'))) !== 28) {
            throw new InvalidArgumentException('release_catalog_count_invalid');
        }
        $groups = array_fill_keys($groupOrder, 0);
        foreach ($definitions as $definition) {
            $groups[$definition['catalog_group']]++;
        }
        foreach ($groups as $count) {
            if ($count === 0) {
                throw new InvalidArgumentException('release_catalog_group_empty');
            }
        }
    }

    /** @param list<string> $groups @param list<string> $codes */
    private function typeScript(array $groups, array $codes): string
    {
        $union = static fn (array $values): string => implode(' | ', array_map(
            static fn (string $value): string => "'{$value}'",
            $values,
        ));

        return 'export type ReportCatalogGroup = '.$union($groups).";\n"
            .'export type PublishedReportCode = '.($codes === [] ? 'never' : $union($codes)).";\n";
    }
}
