<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\OfficialDocumentDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDeliveryReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFormulaReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadiness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReportManifestIdentityContractTest extends TestCase
{
    private const MANAGEMENT_CODES = [
        'project_portfolio_health',
        'holding_performance',
        'intercompany_contract_flows',
        'portfolio_liquidity',
        'project_evm_control',
        'baseline_schedule_variance',
        'lookahead_readiness',
        'accepted_production_progress',
        'project_margin',
        'budget_plan_fact',
        'wip_completion_forecast',
        'contract_settlement_exposure',
        'management_pnl',
        'change_claim_contingency',
        'procurement_cycle',
        'supplier_award_competitiveness',
        'supply_reliability',
        'inventory_risk',
        'workforce_capacity',
        'attendance_execution',
        'project_labor_cost',
        'payroll_readiness',
        'quality_defect_flow',
        'safety_incident_actions',
        'workforce_admission',
        'handover_readiness',
        'contractor_scorecard',
        'customer_sla',
    ];

    private const GROUP_CODES = [
        'portfolio' => [
            'project_portfolio_health',
            'holding_performance',
        ],
        'projects' => [
            'project_evm_control',
            'baseline_schedule_variance',
            'lookahead_readiness',
            'accepted_production_progress',
            'change_claim_contingency',
            'handover_readiness',
        ],
        'finance' => [
            'intercompany_contract_flows',
            'portfolio_liquidity',
            'project_margin',
            'budget_plan_fact',
            'wip_completion_forecast',
            'contract_settlement_exposure',
            'management_pnl',
        ],
        'procurement_warehouse' => [
            'procurement_cycle',
            'supplier_award_competitiveness',
            'supply_reliability',
            'inventory_risk',
        ],
        'team' => [
            'workforce_capacity',
            'attendance_execution',
            'project_labor_cost',
            'payroll_readiness',
        ],
        'quality_safety' => [
            'quality_defect_flow',
            'safety_incident_actions',
            'workforce_admission',
        ],
        'partners_customers' => [
            'contractor_scorecard',
            'customer_sla',
        ],
    ];

    public function test_management_manifest_has_exact_ordered_unique_identity_set(): void
    {
        $codes = array_column($this->managementDefinitions(), 'code');

        self::assertSame(self::MANAGEMENT_CODES, $codes);
        self::assertCount(28, $codes);
        self::assertCount(28, array_unique($codes));
    }

    public function test_management_manifest_has_exact_wave_distribution(): void
    {
        $waves = array_count_values(array_column($this->managementDefinitions(), 'wave'));
        ksort($waves);

        self::assertSame([1 => 12, 2 => 10, 3 => 6], $waves);
    }

    public function test_catalog_groups_are_closed_ordered_and_non_empty(): void
    {
        $orderedGroups = array_map(
            static fn (ReportCatalogGroup $group): string => $group->value,
            ReportCatalogGroup::ordered(),
        );

        self::assertSame(array_keys(self::GROUP_CODES), $orderedGroups);

        foreach ($orderedGroups as $group) {
            self::assertNotEmpty($this->codesForGroup($group), $group);
        }
    }

    public function test_management_manifest_has_exact_group_mapping(): void
    {
        foreach (self::GROUP_CODES as $group => $expectedCodes) {
            self::assertSame($expectedCodes, $this->codesForGroup($group), $group);
        }
    }

    public function test_m29_is_separated_from_management_catalog(): void
    {
        $managementCodes = array_column($this->managementDefinitions(), 'code');
        $official = $this->officialDefinitions();

        self::assertNotContains('official_material_usage_m29', $managementCodes);
        self::assertCount(1, $official);
        self::assertSame('official_material_usage_m29', $official[0]['code']);
        self::assertNotContains($official[0]['code'], $managementCodes);
    }

    public function test_manifests_are_valid_utf8_and_have_exact_required_keys(): void
    {
        foreach (['management-catalog.v1.yaml', 'official-document-catalog.v1.yaml'] as $file) {
            $bytes = (string) file_get_contents($this->resourcePath($file));
            self::assertTrue(mb_check_encoding($bytes, 'UTF-8'), $file);
        }

        self::assertSame(
            ['catalog', 'contract_version', 'definitions'],
            array_keys($this->managementDocument()),
        );
        self::assertSame(
            ['catalog', 'contract_version', 'definitions'],
            array_keys($this->officialDocument()),
        );

        $managementKeys = [
            'code',
            'title_key',
            'catalog_group',
            'category',
            'grain',
            'wave',
            'filters',
            'columns',
            'sorts',
            'formats',
            'versions',
            'permissions',
            'readiness',
            'capabilities',
        ];
        foreach ($this->managementDefinitions() as $definition) {
            self::assertSame($managementKeys, array_keys($definition), $definition['code']);
        }

        self::assertSame(
            [
                'code',
                'title_key',
                'renderer_version',
                'publication_readiness',
                'legal_retention_policy',
                'seal_requires',
            ],
            array_keys($this->officialDefinitions()[0]),
        );
    }

    public function test_permissions_and_formats_never_contain_duplicates(): void
    {
        foreach ($this->managementDefinitions() as $definition) {
            foreach (['view', 'export', 'sensitive', 'audit'] as $permissionType) {
                $permissions = $definition['permissions'][$permissionType];
                self::assertSame(
                    count($permissions),
                    count(array_unique($permissions)),
                    $definition['code'].':'.$permissionType,
                );
            }

            self::assertSame(
                count($definition['formats']),
                count(array_unique($definition['formats'])),
                $definition['code'].':formats',
            );
        }
    }

    public function test_readiness_enums_are_closed_to_manifest_contract(): void
    {
        self::assertSame(
            ['ready', 'partial', 'aggregation_required', 'event_required', 'blocked_by_source'],
            array_column(ReportSourceReadiness::cases(), 'value'),
        );
        self::assertSame(
            ['ready', 'contract_required', 'policy_required', 'blocked_by_source'],
            array_column(ReportFormulaReadiness::cases(), 'value'),
        );
        self::assertSame(
            ['not_implemented', 'verified'],
            array_column(ReportDeliveryReadiness::cases(), 'value'),
        );
    }

    public function test_official_definition_preserves_seal_contract(): void
    {
        $row = $this->officialDefinitions()[0];
        $definition = new OfficialDocumentDefinition(
            code: $row['code'],
            titleKey: $row['title_key'],
            rendererVersion: $row['renderer_version'],
            publicationReadiness: ReportPublicationReadiness::from($row['publication_readiness']),
            legalRetentionPolicy: $row['legal_retention_policy'],
            sealRequires: $row['seal_requires'],
        );

        self::assertSame('official_material_usage_m29', $definition->code);
        self::assertSame('reports.official.official_material_usage_m29', $definition->titleKey);
        self::assertSame('1.0.0', $definition->rendererVersion);
        self::assertSame(ReportPublicationReadiness::BLOCKED, $definition->publicationReadiness);
        self::assertSame('unassigned', $definition->legalRetentionPolicy);
        self::assertSame(
            [
                'opening_balance',
                'receipts',
                'actual_consumption',
                'approved_normative_consumption',
                'closing_balance',
                'source_refs',
                'versioned_coefficients',
            ],
            $definition->sealRequires,
        );
    }

    private function codesForGroup(string $group): array
    {
        return array_values(array_column(array_filter(
            $this->managementDefinitions(),
            static fn (array $definition): bool => $definition['catalog_group'] === $group,
        ), 'code'));
    }

    private function managementDefinitions(): array
    {
        return $this->managementDocument()['definitions'];
    }

    private function officialDefinitions(): array
    {
        return $this->officialDocument()['definitions'];
    }

    private function managementDocument(): array
    {
        return Yaml::parseFile($this->resourcePath('management-catalog.v1.yaml'));
    }

    private function officialDocument(): array
    {
        return Yaml::parseFile($this->resourcePath('official-document-catalog.v1.yaml'));
    }

    private function resourcePath(string $file): string
    {
        return dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
