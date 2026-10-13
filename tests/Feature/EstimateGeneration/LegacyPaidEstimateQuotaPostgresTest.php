<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyPaidEstimateConversionEvidence;
use App\Models\Organization;
use App\Models\OrganizationResourceAllocation;
use App\Services\Billing\CommercialQuotaService;
use App\Services\Credits\AICreditService;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class LegacyPaidEstimateQuotaPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_formatted_ledger_metadata_without_immutable_financial_proof_cannot_retire_the_legacy_addon(): void
    {
        config(['estimate-generation.credits.enabled' => true, 'commercial_limits.ai_estimates.included_monthly' => 10]);
        $organization = Organization::factory()->create();
        $allocation = $this->allocation($organization);
        $quota = app(CommercialQuotaService::class);
        self::assertSame(20, $quota->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        self::assertFalse(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
        $metadata = $this->metadata($allocation);
        app(AICreditService::class)->grant($organization, 600, 'conversion', null,
            'legacy-estimator-paid-addon:'.$allocation->id, metadata: $metadata);
        self::assertSame(20, $quota->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        self::assertFalse(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
        $summary = $quota->getQuotaSummary($organization);
        $row = collect($summary['limits'])->firstWhere('key', 'ai_estimates_month');
        self::assertSame(20, $row['limit']);
        self::assertSame(10, $row['sources']['paid_addons']);
        self::assertSame('10.00', $allocation->fresh()->quantity);
        self::assertSame('active', $allocation->fresh()->status);
        self::assertSame($metadata['allocation_snapshot_hash'], LegacyPaidEstimateConversionEvidence::allocationHash($allocation->fresh()));
    }

    public function test_corrupted_amount_or_other_organizations_evidence_does_not_remove_the_old_paid_quota(): void
    {
        config(['estimate-generation.credits.enabled' => true, 'commercial_limits.ai_estimates.included_monthly' => 10]);
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $allocation = $this->allocation($organization);
        $metadata = $this->metadata($allocation);
        app(AICreditService::class)->grant($other, 600, 'conversion', null,
            'legacy-estimator-paid-addon:'.$allocation->id, metadata: $metadata);
        $quota = app(CommercialQuotaService::class);
        self::assertSame(20, $quota->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        $metadata['converted_units_minor'] = 601;
        app(AICreditService::class)->grant($organization, 601, 'conversion', null,
            'legacy-estimator-paid-addon:'.$allocation->id, metadata: $metadata);
        self::assertSame(20, $quota->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        self::assertFalse(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
    }

    private function allocation(Organization $organization): OrganizationResourceAllocation
    {
        return OrganizationResourceAllocation::query()->create(['organization_id' => $organization->id,
            'resource_slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month', 'quantity' => 10,
            'source' => 'paid_addon', 'status' => 'active', 'period_start_at' => now()->subDay(), 'period_end_at' => now()->addDay(),
            'metadata' => ['commercial_order_id' => 'fixture-old-purchase', 'amount_minor' => 1000, 'currency' => 'RUB']]);
    }

    private function metadata(OrganizationResourceAllocation $allocation): array
    {
        return ['migration_version' => 'estimator-paid:v1', 'allocation_id' => (int) $allocation->id,
            'allocation_snapshot_hash' => LegacyPaidEstimateConversionEvidence::allocationHash($allocation),
            'converted_units_minor' => 600, 'remaining_paid_amount_minor' => 600,
            'commercial_sale_price_minor_per_unit' => 100, 'commercial_price_version' => 2,
            'remaining_money_proof_hash' => hash('sha256', 'remaining cash reconciliation fixture')];
    }
}
