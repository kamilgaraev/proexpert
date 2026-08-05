<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use LogicException;

final class ReportManifestSemanticValidator
{
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

    public function assertManagement(array $document): void
    {
        $definitions = $document['definitions'] ?? null;
        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new LogicException('report_manifest_definitions_invalid');
        }

        foreach ($definitions as $definition) {
            if (is_array($definition) && ($definition['code'] ?? null) === 'official_material_usage_m29') {
                throw new LogicException('report_manifest_m29_catalog_invalid');
            }
        }

        $seenCodes = [];
        $actualGroups = array_fill_keys(array_keys(self::GROUP_CODES), []);
        $waves = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                throw new LogicException('report_manifest_definition_invalid');
            }

            $code = $definition['code'] ?? null;
            $group = $definition['catalog_group'] ?? null;
            $wave = $definition['wave'] ?? null;
            if (! is_string($code) || isset($seenCodes[$code])) {
                throw new LogicException(
                    is_string($code) && isset($seenCodes[$code])
                        ? 'report_manifest_code_duplicate'
                        : 'report_manifest_definition_invalid',
                );
            }
            if (! is_string($group) || ! array_key_exists($group, $actualGroups) || ! is_int($wave)) {
                throw new LogicException('report_manifest_identity_invalid');
            }

            $seenCodes[$code] = true;
            $actualGroups[$group][] = $code;
            $waves[$wave] = ($waves[$wave] ?? 0) + 1;

            $this->assertDefinitionSemantics($definition, $code);
        }

        foreach (self::GROUP_CODES as $group => $expectedCodes) {
            $actualCodes = $actualGroups[$group];
            sort($actualCodes, SORT_STRING);
            sort($expectedCodes, SORT_STRING);
            if ($actualCodes !== $expectedCodes) {
                throw new LogicException('report_manifest_group_mapping_invalid');
            }
        }

        ksort($waves);
        if ($waves !== [1 => 12, 2 => 10, 3 => 6]) {
            throw new LogicException('report_manifest_wave_distribution_invalid');
        }
    }

    public function assertOfficial(array $document): void
    {
        $definitions = $document['definitions'] ?? null;
        if (! is_array($definitions)
            || ! array_is_list($definitions)
            || count($definitions) !== 1
            || ! is_array($definitions[0])
            || ($definitions[0]['code'] ?? null) !== 'official_material_usage_m29') {
            throw new LogicException('report_manifest_official_identity_invalid');
        }
    }

    private function assertDefinitionSemantics(array $definition, string $code): void
    {
        $this->assertCoreAccessContract($definition);

        if (($definition['title_key'] ?? null) !== 'reports.catalog.'.$code) {
            throw new LogicException('report_manifest_title_key_invalid');
        }

        foreach (['filters', 'columns', 'sorts'] as $collectionName) {
            $collection = $definition[$collectionName] ?? null;
            if (! is_array($collection) || ! array_is_list($collection)) {
                throw new LogicException('report_manifest_contract_collection_invalid');
            }

            $ids = [];
            foreach ($collection as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (! is_string($id)
                    || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $id) !== 1
                    || isset($ids[$id])) {
                    throw new LogicException('report_manifest_contract_id_invalid');
                }
                $ids[$id] = true;
            }
        }

        $permissions = $definition['permissions'] ?? null;
        if (! is_array($permissions)) {
            throw new LogicException('report_manifest_permission_policy_invalid');
        }
        foreach ($permissions as $slugs) {
            if (! is_array($slugs) || ! array_is_list($slugs)) {
                throw new LogicException('report_manifest_permission_policy_invalid');
            }
            foreach ($slugs as $slug) {
                if (! is_string($slug)
                    || preg_match('/^[a-z0-9][a-z0-9._-]+$/D', $slug) !== 1
                    || str_contains($slug, '*')) {
                    throw new LogicException('report_manifest_permission_key_invalid');
                }
            }
        }

        $readiness = $definition['readiness'] ?? null;
        $capabilities = $definition['capabilities'] ?? null;
        if (! is_array($readiness) || ! is_array($capabilities)) {
            throw new LogicException('report_manifest_readiness_invalid');
        }

        $publication = $readiness['publication'] ?? null;
        if (in_array($publication, ['candidate', 'published'], true)) {
            foreach (['filters', 'columns', 'sorts', 'formats'] as $collectionName) {
                if (($definition[$collectionName] ?? []) === []) {
                    throw new LogicException('report_manifest_candidate_capability_empty');
                }
            }
        }

        if ($publication === 'published'
            && (($readiness['source'] ?? null) !== 'ready'
                || ($readiness['formula'] ?? null) !== 'ready'
                || ($readiness['delivery'] ?? null) !== 'verified')) {
            throw new LogicException('report_manifest_published_readiness_invalid');
        }

        $supportsSubscriptions = $capabilities['supports_subscriptions'] ?? null;
        $reproducibleSnapshot = $capabilities['reproducible_scheduled_snapshot'] ?? null;
        if (! is_bool($supportsSubscriptions) || ! is_bool($reproducibleSnapshot)) {
            throw new LogicException('report_manifest_scheduling_capability_invalid');
        }
        if ($supportsSubscriptions && ! $reproducibleSnapshot) {
            throw new LogicException('report_manifest_scheduling_capability_invalid');
        }
        if (in_array($publication, ['draft', 'blocked'], true)
            && ($supportsSubscriptions || $reproducibleSnapshot)) {
            throw new LogicException('report_manifest_scheduling_readiness_invalid');
        }
    }

    private function assertCoreAccessContract(array $definition): void
    {
        $mode = $definition['core_access_mode'] ?? 'reporting_workspace';
        $sourceModule = $definition['source_module'] ?? null;

        if ($mode === 'reporting_workspace') {
            if ($sourceModule !== null && $sourceModule !== 'reports') {
                throw new LogicException('report_manifest_core_access_invalid');
            }

            return;
        }

        if ($mode !== 'source_module_report'
            || ! array_key_exists('source_module', $definition)
            || ! is_string($sourceModule)
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $sourceModule) !== 1
            || $sourceModule === 'reports') {
            throw new LogicException('report_manifest_core_access_invalid');
        }
    }
}
