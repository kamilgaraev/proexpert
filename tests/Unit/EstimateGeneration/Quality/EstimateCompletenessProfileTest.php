<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateCompletenessProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateCompletenessProfileTest extends TestCase
{
    #[Test]
    public function it_marks_a_requested_heating_system_as_partial_when_distribution_and_devices_are_missing(): void
    {
        $profile = (new EstimateCompletenessProfile)->project($this->draft([
            'heating' => ['heating.unit'],
        ]));

        self::assertSame('confirmed_scope_only', $profile['status']);
        self::assertSame('unresolved', $profile['scopes']['heating']['state']);
        self::assertSame(['heating.pipe', 'heating.radiators'], $profile['scopes']['heating']['missing_items']);
    }

    #[Test]
    public function it_accepts_an_exclusion_only_when_it_has_a_direct_evidence_reference(): void
    {
        $profile = (new EstimateCompletenessProfile)->project([
            ...$this->draft(['heating' => []]),
            'completeness_exclusions' => [
                'heating' => [
                    'reason' => 'user_decision',
                    'evidence_refs' => ['input:scope:heating'],
                ],
            ],
        ]);

        self::assertSame('excluded', $profile['scopes']['heating']['state']);
        self::assertSame(['input:scope:heating'], $profile['scopes']['heating']['evidence_refs']);
    }

    #[Test]
    public function it_does_not_invent_pitched_roof_layers_when_only_covering_is_confirmed(): void
    {
        $profile = (new EstimateCompletenessProfile)->project($this->draft([
            'roof' => ['roof.covering'],
        ], 'pitched'));

        self::assertSame('full_confirmed_scope', $profile['status']);
        self::assertSame('covered', $profile['scopes']['roof']['state']);
        self::assertSame([], $profile['scopes']['roof']['missing_items']);
    }

    #[Test]
    public function it_exposes_a_missing_normative_candidate_as_a_roof_scope_gap(): void
    {
        $draft = $this->draft(['roof' => []], 'pitched');
        $draft['local_estimates'][0]['coverage_warnings'] = [[
            'quantity_key' => 'roof.covering',
            'reason' => 'normative_candidate_missing',
            'package_key' => 'roof',
        ]];

        $profile = (new EstimateCompletenessProfile)->project($draft);

        self::assertSame('confirmed_scope_only', $profile['status']);
        self::assertSame('unresolved', $profile['scopes']['roof']['state']);
        self::assertSame([[
            'work_key' => 'roof.covering',
            'reason' => 'normative_candidate_missing',
        ]], $profile['scopes']['roof']['gaps']);
    }

    #[Test]
    public function it_does_not_treat_uncalculated_or_review_rows_as_required_work_coverage(): void
    {
        $draft = $this->draft(['roof' => []], 'pitched');
        $draft['local_estimates'][0]['sections'][0]['work_items'] = [
            [
                'item_type' => 'priced_work',
                'pricing_status' => 'not_calculated',
                'metadata' => ['composition_work_key' => 'roof.covering'],
            ],
            [
                'item_type' => 'review_note',
                'pricing_status' => 'calculated',
                'metadata' => ['composition_work_key' => 'roof.covering'],
            ],
        ];

        $profile = (new EstimateCompletenessProfile)->project($draft);

        self::assertSame('confirmed_scope_only', $profile['status']);
        self::assertSame(['roof.covering'], $profile['scopes']['roof']['missing_items']);
        self::assertSame([[
            'work_key' => 'roof.covering',
            'reason' => 'document_takeoff_missing',
        ]], $profile['scopes']['roof']['gaps']);
    }

    /** @param array<string, list<string>> $workKeys */
    private function draft(array $workKeys, string $roofType = ''): array
    {
        $packages = [];
        $localEstimates = [];
        foreach ($workKeys as $packageKey => $keys) {
            $packages[] = [
                'key' => $packageKey,
                'title' => $packageKey,
                'coverage_required' => true,
            ];
            $localEstimates[] = [
                'key' => $packageKey,
                'sections' => [[
                    'work_items' => array_map(static fn (string $key): array => [
                        'item_type' => 'priced_work',
                        'pricing_status' => 'calculated',
                        'metadata' => ['composition_work_key' => $key],
                    ], $keys),
                ]],
            ];
        }

        return [
            'object_profile' => [
                'object_type' => 'house',
                'floors' => 2,
                'planning_signals' => $roofType === '' ? [] : ['roof_type' => $roofType],
            ],
            'package_plan' => ['packages' => $packages],
            'local_estimates' => $localEstimates,
        ];
    }
}
