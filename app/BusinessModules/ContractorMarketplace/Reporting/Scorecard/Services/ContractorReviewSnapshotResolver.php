<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ContractorReviewSnapshotResolver
{
    public function __construct(private ContractorMembershipEvidenceResolver $memberships) {}

    public function resolve(ReportQuery $query): ReportSnapshotRef
    {
        $coverage = DB::table('contractor_scorecard_review_coverage')
            ->where('source_code', 'marketplace_reviews')
            ->first();
        if (! is_object($coverage)) {
            throw new InvalidArgumentException('contractor_review_evidence_unavailable');
        }
        $coverageStartedAt = CarbonImmutable::parse($coverage->coverage_started_at);
        if (CarbonImmutable::instance($query->asOf)->lt($coverageStartedAt)) {
            throw new InvalidArgumentException('contractor_review_evidence_historical_gap');
        }
        $events = DB::table('contractor_scorecard_review_events')
            ->where('organization_id', $query->scope->organizationId)
            ->where('observed_at', '<=', $query->asOf)
            ->orderBy('review_id')
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();
        $latest = [];
        foreach ($events as $event) {
            $payload = is_string($event->payload) ? json_decode($event->payload, true) : $event->payload;
            if (! is_array($payload)) {
                throw new InvalidArgumentException('contractor_review_evidence_invalid');
            }
            $latest[(int) $event->review_id] = [
                'event_id' => (int) $event->id,
                'evidence_hash' => (string) $event->evidence_hash,
                'is_deleted' => (bool) $event->is_deleted,
                'observed_at' => CarbonImmutable::parse($event->observed_at),
                'payload' => $payload,
            ];
        }
        $candidates = [];
        foreach ($latest as $reviewId => $event) {
            $payload = $event['payload'];
            $createdAt = CarbonImmutable::parse((string) ($payload['created_at'] ?? $event['observed_at']));
            if (
                $event['is_deleted']
                || (int) ($payload['reviewer_organization_id'] ?? 0) !== $query->scope->organizationId
                || ($query->scope->projectIds !== []
                    && ! in_array((int) ($payload['project_id'] ?? 0), $query->scope->projectIds, true))
                || ! $this->matchesCohort($createdAt, $query->filters->values['cohort'] ?? null)
            ) {
                continue;
            }
            $candidates[$reviewId] = ['created_at' => $createdAt, ...$event];
        }
        $timestamps = array_values(array_map(
            static fn (array $candidate): CarbonImmutable => $candidate['created_at'],
            array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate['created_at']->gte($coverageStartedAt),
            ),
        ));
        $membershipAt = $this->memberships->resolveMany(
            $query->scope->organizationId,
            $timestamps,
        );
        $rows = [];
        $unknownCount = 0;
        foreach ($candidates as $reviewId => $candidate) {
            $payload = $candidate['payload'];
            $membership = $membershipAt[$candidate['created_at']->toISOString()] ?? null;
            $profileId = (int) ($payload['contractor_profile_id'] ?? 0);
            $categoryId = (int) ($payload['category_id'] ?? 0);
            if (
                $membership === null
                || ($membership->profileOrganizationById[$profileId] ?? null)
                    !== (int) ($payload['contractor_organization_id'] ?? 0)
                || ! isset($membership->categoriesByProfile[$profileId][$categoryId])
                || (int) ($payload['project_id'] ?? 0) < 1
            ) {
                $unknownCount++;
                continue;
            }
            $rows[] = [
                'review_id' => $reviewId,
                'offer_id' => (int) ($payload['offer_id'] ?? 0),
                'project_id' => (int) $payload['project_id'],
                'contractor_organization_id' => (int) $payload['contractor_organization_id'],
                'contractor_profile_id' => $profileId,
                'category_id' => $categoryId,
                'quality_score' => (string) $payload['quality_score'],
                'deadline_score' => (string) $payload['deadline_score'],
                'communication_score' => (string) $payload['communication_score'],
                'safety_score' => isset($payload['safety_score']) ? (string) $payload['safety_score'] : null,
                'financial_discipline_score' => isset($payload['financial_discipline_score'])
                    ? (string) $payload['financial_discipline_score']
                    : null,
                'created_at' => $candidate['created_at'],
                'membership_evidence_hash' => $membership->sourceHash,
                'row_key' => 'marketplace_review:'.$reviewId,
            ];
        }
        $asOfMembership = $this->memberships->resolve(
            $query->scope->organizationId,
            CarbonImmutable::instance($query->asOf),
        );
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'as_of' => $query->asOf->format(DATE_ATOM),
            'coverage_hash' => (string) $coverage->evidence_hash,
            'events' => array_map(static fn (array $event): array => [
                'event_id' => $event['event_id'],
                'evidence_hash' => $event['evidence_hash'],
            ], $latest),
            'filters' => $query->filters->values,
            'membership_evidence_hash' => $asOfMembership->sourceHash,
            'scope' => $query->scope->canonicalIdentity(),
            'unknown_count' => $unknownCount,
        ]));
        $snapshotId = (string) Str::ulid();
        $generatedAt = CarbonImmutable::now('UTC');
        DB::transaction(function () use (
            $query,
            $sourceHash,
            $snapshotId,
            $generatedAt,
            $unknownCount,
            $rows,
        ): void {
            DB::table('organizations')
                ->where('id', $query->scope->organizationId)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = DB::table('contractor_scorecard_review_snapshots')
                ->where('organization_id', $query->scope->organizationId)
                ->where('source_hash', $sourceHash)
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                return;
            }
            DB::table('contractor_scorecard_review_snapshots')->insert([
                'id' => $snapshotId,
                'organization_id' => $query->scope->organizationId,
                'source_hash' => $sourceHash,
                'as_of' => $query->asOf,
                'scope_identity' => CanonicalJson::encode($query->scope->canonicalIdentity()),
                'filters' => CanonicalJson::encode($query->filters->values),
                'row_count' => count($rows),
                'unknown_count' => $unknownCount,
                'generated_at' => $generatedAt,
            ]);
            foreach ($rows as $row) {
                DB::table('contractor_scorecard_review_snapshot_rows')->insert([
                    'organization_id' => $query->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    ...$row,
                ]);
            }
        }, 3);
        $snapshot = DB::table('contractor_scorecard_review_snapshots')
            ->where('organization_id', $query->scope->organizationId)
            ->where('source_hash', $sourceHash)
            ->first();
        if (! is_object($snapshot)) {
            throw new InvalidArgumentException('contractor_review_snapshot_unavailable');
        }

        return new ReportSnapshotRef(
            'marketplace_reviews',
            (string) $snapshot->id,
            $query->scope,
            new Sha256Hash(hash('sha256', 'marketplace-reviews.v1')),
            'marketplace-reviews.v1',
            new Sha256Hash($sourceHash),
            DateTimeImmutable::createFromInterface(CarbonImmutable::parse($snapshot->generated_at)),
            null,
            [
                'source_schema_version' => 'marketplace-reviews.v1',
                'as_of' => $query->asOf->format(DATE_ATOM),
                'cohort_key' => $query->filters->values['cohort'] ?? null,
                'project_ids' => $query->scope->projectIds,
                'membership_coverage_started_at' => $asOfMembership->coverageStartedAt,
                'membership_evidence_hash' => $asOfMembership->sourceHash,
                'row_count' => (int) $snapshot->row_count,
                'unknown_count' => (int) $snapshot->unknown_count,
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function matchesCohort(CarbonImmutable $createdAt, mixed $cohort): bool
    {
        if ($cohort === null) {
            return true;
        }
        if (! is_string($cohort)) {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}$/D', $cohort) === 1) {
            return $createdAt->format('Y-m') === $cohort;
        }
        if (preg_match('/^(\d{4})-Q([1-4])$/D', $cohort, $matches) === 1) {
            return $createdAt->year === (int) $matches[1] && $createdAt->quarter === (int) $matches[2];
        }

        return preg_match('/^\d{4}$/D', $cohort) === 1 && $createdAt->format('Y') === $cohort;
    }
}
