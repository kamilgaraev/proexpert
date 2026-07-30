<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Readiness;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardPolicyVersion;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardObservationReader;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardSourceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ContractorScorecardReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ContractorScorecardSourceResolver $sources,
        private ContractorScorecardObservationReader $observations,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'contractor_scorecard'
            && $definition->formulaVersion === 'contractor-scorecard.v1'
            && $definition->sourceSchemaVersion === 'contractor-scorecard.v1';
    }

    public function reportCodes(): array
    {
        return ['contractor_scorecard'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        try {
            $tuple = $this->sources->resolve($context, $query);
            $policy = ContractorScorecardPolicyVersion::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('effective_from', '<=', $query->asOf)
                ->where(static function ($builder) use ($query): void {
                    $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf);
                })
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
            if (! $policy instanceof ContractorScorecardPolicyVersion) {
                throw new \RuntimeException('contractor_scorecard_policy_unavailable');
            }
            $reviews = DB::table('contractor_scorecard_review_snapshot_rows')
                ->where('organization_id', $context->scope->organizationId)
                ->where('snapshot_id', $tuple->marketplaceReviews->id)
                ->orderBy('review_id')
                ->get();
            $objectiveObservations = $this->observations->load($tuple);
            $validReviews = $reviews;
            $projected = $validReviews->count();
            $eligible = $reviews->count();
            $unknown = $eligible - $projected
                + (int) ($tuple->marketplaceReviews->watermarks['unknown_count'] ?? 0);
            $objectiveDimensions = $objectiveObservations->profileProjects();
            foreach ($objectiveDimensions as $profileId => $projects) {
                foreach (array_keys($projects) as $projectId) {
                    $eligible++;
                    $hasCategory = $objectiveObservations->categoryIds((int) $profileId) !== [];
                    if ($hasCategory) {
                        $projected++;
                    } else {
                        $unknown++;
                    }
                }
            }
            $input = [
                'review_ids' => $reviews->pluck('review_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                'policy_source_hash' => (string) $policy->source_hash,
                'tuple_hash' => $tuple->hash(),
            ];

            return new ReportSourceReadiness(
                $unknown === 0
                    ? ReportSourceReadinessStatus::READY
                    : ReportSourceReadinessStatus::PARTIAL,
                $eligible,
                $projected,
                0,
                $unknown,
                $tuple->hash(),
                hash('sha256', CanonicalJson::encode($input)),
                hash('sha256', CanonicalJson::encode([
                    'projected_review_ids' => $validReviews->pluck('review_id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->values()
                        ->all(),
                    'tuple_hash' => $tuple->hash(),
                ])),
                $unknown === 0 ? CarbonImmutable::now('UTC') : null,
            );
        } catch (Throwable) {
            $hash = hash('sha256', CanonicalJson::encode([
                'as_of' => $query->asOf->format(DATE_ATOM),
                'organization_id' => $context->scope->organizationId,
                'status' => 'unavailable',
            ]));

            return new ReportSourceReadiness(
                ReportSourceReadinessStatus::UNAVAILABLE,
                0,
                0,
                0,
                0,
                'unavailable',
                $hash,
                $hash,
                null,
            );
        }
    }
}
