<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardPolicyVersion;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ContractorScorecardPolicyWriter
{
    private const SOURCE_UNITS = [
        'baseline_schedule_variance' => ['count', 'days', 'ratio'],
        'supply_reliability' => ['count', 'ratio'],
        'quality_defect_flow' => ['count', 'days', 'ratio'],
        'safety_incident_actions' => ['count', 'days', 'ratio'],
        'marketplace_reviews' => ['score_0_5'],
    ];

    private const REVIEW_COMPONENTS = [
        'marketplace_quality',
        'marketplace_deadline',
        'marketplace_communication',
        'marketplace_safety',
        'marketplace_financial_discipline',
    ];

    private const REVIEW_METRICS = [
        'marketplace_quality' => 'quality_score',
        'marketplace_deadline' => 'deadline_score',
        'marketplace_communication' => 'communication_score',
        'marketplace_safety' => 'safety_score',
        'marketplace_financial_discipline' => 'financial_discipline_score',
    ];

    private const SOURCE_METRICS = [
        'baseline_schedule_variance' => [
            'variance_days' => 'days',
            'critical_flag' => 'ratio',
            'task_count' => 'count',
        ],
        'supply_reliability' => [
            'otif' => 'ratio',
            'delivery_count' => 'count',
        ],
        'quality_defect_flow' => [
            'cycle_days' => 'days',
            'closed_flag' => 'ratio',
            'defect_count' => 'count',
        ],
        'safety_incident_actions' => [
            'closure_days' => 'days',
            'closure_verified' => 'ratio',
            'action_count' => 'count',
        ],
    ];

    public function append(
        int $organizationId,
        array $components,
        array $cohortRules,
        string $minimumCoverage,
        int $minimumSampleSize,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo = null,
    ): ContractorScorecardPolicyVersion {
        $this->assertInput(
            $organizationId,
            $components,
            $cohortRules,
            $minimumCoverage,
            $minimumSampleSize,
            $effectiveFrom,
            $effectiveTo,
        );

        return DB::transaction(function () use (
            $organizationId,
            $components,
            $cohortRules,
            $minimumCoverage,
            $minimumSampleSize,
            $effectiveFrom,
            $effectiveTo,
        ): ContractorScorecardPolicyVersion {
            if (! DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('contractor_scorecard_policy_organization_invalid');
            }
            $last = ContractorScorecardPolicyVersion::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            $sequence = $last === null
                ? 1
                : ((int) preg_replace('/\D+/', '', (string) $last->version)) + 1;
            $version = 'contractor-scorecard.v'.$sequence;
            $payload = [
                'cohort_rules' => $cohortRules,
                'components' => $components,
                'effective_from' => $effectiveFrom->toISOString(),
                'effective_to' => $effectiveTo?->toISOString(),
                'minimum_coverage' => $minimumCoverage,
                'minimum_sample_size' => $minimumSampleSize,
                'organization_id' => $organizationId,
                'version' => $version,
            ];

            return ContractorScorecardPolicyVersion::query()->create([
                ...$payload,
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
        }, 3);
    }

    private function assertInput(
        int $organizationId,
        array $components,
        array $cohortRules,
        string $minimumCoverage,
        int $minimumSampleSize,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
    ): void {
        if (
            $organizationId < 1
            || $components === []
            || ! array_is_list($components)
            || array_keys($cohortRules) !== ['period']
            || ! in_array($cohortRules['period'], ['month', 'quarter', 'year'], true)
            || preg_match('/^(?:0(?:\.[0-9]{1,8})?|1(?:\.0{1,8})?)$/D', $minimumCoverage) !== 1
            || bccomp($minimumCoverage, '0', 8) < 0
            || bccomp($minimumCoverage, '1', 8) > 0
            || $minimumSampleSize < 1
            || ($effectiveTo !== null && $effectiveTo <= $effectiveFrom)
        ) {
            throw new InvalidArgumentException('contractor_scorecard_policy_invalid');
        }

        $codes = [];
        foreach ($components as $component) {
            if (
                ! is_array($component)
                || ! is_string($component['code'] ?? null)
                || ! is_string($component['unit_code'] ?? null)
                || ! is_string($component['source_report_code'] ?? null)
                || ! is_string($component['source_formula_version'] ?? null)
                || ! is_string($component['source_schema_version'] ?? null)
                || ! is_string($component['source_metric'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $component['code']) !== 1
                || trim($component['source_formula_version']) === ''
                || trim($component['source_schema_version']) === ''
                || ! isset(self::SOURCE_UNITS[$component['source_report_code']])
                || ! in_array(
                    $component['unit_code'],
                    self::SOURCE_UNITS[$component['source_report_code']],
                    true,
                )
                || ($component['source_report_code'] === 'marketplace_reviews'
                    && ! in_array($component['code'], self::REVIEW_COMPONENTS, true))
                || ($component['source_report_code'] !== 'marketplace_reviews'
                    && (self::SOURCE_METRICS[$component['source_report_code']][$component['source_metric']] ?? null)
                        !== $component['unit_code'])
                || ($component['source_report_code'] === 'marketplace_reviews'
                    && (self::REVIEW_METRICS[$component['code']] ?? null) !== $component['source_metric'])
                || isset($codes[$component['code']])
            ) {
                throw new InvalidArgumentException('contractor_scorecard_policy_invalid');
            }
            $codes[$component['code']] = true;
        }
    }
}
