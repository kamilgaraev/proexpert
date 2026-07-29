<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Readiness;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
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
    public function __construct(private ContractorScorecardSourceResolver $sources) {}

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
            $reviews = MarketplaceHiringOfferReview::query()
                ->where('reviewer_organization_id', $context->scope->organizationId)
                ->where('created_at', '<=', $query->asOf)
                ->when(
                    $query->scope->projectIds !== [],
                    fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
                )
                ->orderBy('id')
                ->get();
            $profiles = DB::table('marketplace_contractor_profiles')
                ->whereIn('id', $reviews->pluck('contractor_profile_id')->all())
                ->pluck('organization_id', 'id');
            $validReviews = $reviews->filter(
                static fn (MarketplaceHiringOfferReview $review): bool => isset($profiles[(int) $review->contractor_profile_id])
                    && (int) $profiles[(int) $review->contractor_profile_id]
                        === (int) $review->contractor_organization_id
                    && (int) $review->category_id > 0
                    && (int) $review->project_id > 0,
            );
            $projected = $validReviews->count();
            $eligible = $reviews->count();
            $unknown = $eligible - $projected;
            $input = [
                'review_ids' => $reviews->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
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
                    'projected_review_ids' => $validReviews->pluck('id')
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
