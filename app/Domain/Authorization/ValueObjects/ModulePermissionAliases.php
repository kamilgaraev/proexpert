<?php

declare(strict_types=1);

namespace App\Domain\Authorization\ValueObjects;

final class ModulePermissionAliases
{
    private const CANONICAL_BY_ALIAS = [
        'projects' => 'project-management',
        'schedule' => 'schedule-management',
        'schedule_management' => 'schedule-management',
        'construction-journal' => 'budget-estimates',
        'construction_journal' => 'budget-estimates',
        'estimates' => 'budget-estimates',
        'act_reports' => 'act-reporting',
        'act-reports' => 'act-reporting',
        'ai_estimates' => 'ai-estimates',
        'estimate_generation' => 'ai-estimates',
        'time_tracking' => 'time-tracking',
        'report_templates' => 'report-templates',
        'warehouse' => 'basic-warehouse',
        'contracts' => 'contract-management',
        'mdm' => 'catalog-management',
        'materials' => 'catalog-management',
        'suppliers' => 'catalog-management',
        'contractors' => 'catalog-management',
        'work_types' => 'catalog-management',
        'work-types' => 'catalog-management',
        'measurement_units' => 'catalog-management',
        'measurement-units' => 'catalog-management',
        'cost_categories' => 'catalog-management',
        'cost-categories' => 'catalog-management',
        'completed_works' => 'workflow-management',
        'completed-works' => 'workflow-management',
        'workforce' => 'workforce-management',
        'one_c_exchange' => 'one-c-basic-exchange',
        'one-c-exchange' => 'one-c-basic-exchange',
        'organizations' => 'contractor-portal',
        'contractor_invitations' => 'contractor-portal',
        'contractor-invitations' => 'contractor-portal',
        'contractor_marketplace' => 'contractor-portal',
        'contractor-marketplace' => 'contractor-portal',
    ];

    private const PREFERRED_ALIAS_BY_CANONICAL = [
        'project-management' => 'projects',
        'budget-estimates' => 'estimates',
        'schedule-management' => 'schedule',
        'act-reporting' => 'act_reports',
        'time-tracking' => 'time_tracking',
        'report-templates' => 'report_templates',
        'basic-warehouse' => 'warehouse',
        'contract-management' => 'contracts',
        'catalog-management' => 'mdm',
        'workflow-management' => 'completed_works',
        'workforce-management' => 'workforce',
        'one-c-basic-exchange' => 'one_c_exchange',
        'contractor-portal' => 'contractor_marketplace',
        'ai-estimates' => 'estimate_generation',
    ];

    public static function variants(string $module): array
    {
        $normalizedHyphen = str_replace('_', '-', $module);
        $normalizedUnderscore = str_replace('-', '_', $module);
        $variants = [$module, $normalizedHyphen, $normalizedUnderscore];

        foreach ([$module, $normalizedHyphen, $normalizedUnderscore] as $candidate) {
            if (isset(self::CANONICAL_BY_ALIAS[$candidate])) {
                $variants[] = self::CANONICAL_BY_ALIAS[$candidate];
            }

            if (isset(self::PREFERRED_ALIAS_BY_CANONICAL[$candidate])) {
                $variants[] = self::PREFERRED_ALIAS_BY_CANONICAL[$candidate];
            }
        }

        return array_values(array_unique($variants));
    }
}
