<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorMembershipEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ContractorMembershipEvidenceResolver
{
    private const SUBJECT_TYPES = ['contractor', 'supplier', 'profile', 'profile_category'];

    public function resolve(int $organizationId, CarbonImmutable $asOf): ContractorMembershipEvidence
    {
        $coverage = DB::table('contractor_scorecard_membership_coverage')
            ->whereIn('subject_type', self::SUBJECT_TYPES)
            ->orderBy('subject_type')
            ->get(['subject_type', 'coverage_started_at', 'evidence_hash']);
        if ($coverage->count() !== count(self::SUBJECT_TYPES)) {
            throw new InvalidArgumentException('contractor_membership_evidence_unavailable');
        }
        $coverageStartedAt = $coverage
            ->map(static fn (object $row): CarbonImmutable => CarbonImmutable::parse($row->coverage_started_at))
            ->max();
        if (! $coverageStartedAt instanceof CarbonImmutable || $asOf->lt($coverageStartedAt)) {
            throw new InvalidArgumentException('contractor_membership_evidence_historical_gap');
        }

        $scopedEvents = DB::table('contractor_scorecard_membership_events')
            ->whereIn('subject_type', ['contractor', 'supplier'])
            ->where('organization_id', $organizationId)
            ->where('observed_at', '<=', $asOf)
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();
        $latest = $this->latest($scopedEvents);
        $profileIds = [];
        $profileOrganizationIds = [];
        foreach ($latest['contractor'] ?? [] as $event) {
            $profileOrganizationIds[] = (int) ($event['payload']['source_organization_id'] ?? 0);
        }
        foreach ($latest['supplier'] ?? [] as $event) {
            $metadata = $event['payload']['additional_info'] ?? null;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }
            if (is_array($metadata)) {
                $profileIds[] = (int) ($metadata['contractor_profile_id'] ?? 0);
                $profileOrganizationIds[] = (int) ($metadata['contractor_organization_id'] ?? 0);
            }
        }
        $profileIds = array_values(array_filter(array_unique($profileIds)));
        $profileOrganizationIds = array_values(array_filter(array_unique($profileOrganizationIds)));
        $profileEvents = DB::table('contractor_scorecard_membership_events')
            ->where('subject_type', 'profile')
            ->where('observed_at', '<=', $asOf)
            ->where(static function ($query) use ($profileIds, $profileOrganizationIds): void {
                $query->whereIn('subject_id', $profileIds)
                    ->orWhereIn('organization_id', $profileOrganizationIds);
            })
            ->orderBy('subject_id')
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();
        $latest += $this->latest($profileEvents);
        $profilesById = [];
        $profileOrganizationById = [];
        $profileByOrganization = [];
        foreach ($latest['profile'] ?? [] as $event) {
            if ($event['is_deleted']) {
                continue;
            }
            $profileId = (int) $event['subject_id'];
            $profileOrganizationId = (int) ($event['payload']['organization_id'] ?? 0);
            if ($profileId > 0 && $profileOrganizationId > 0) {
                $profilesById[$profileId] = $profileId;
                $profileOrganizationById[$profileId] = $profileOrganizationId;
                $profileByOrganization[$profileOrganizationId] = $profileId;
            }
        }

        $profileByContractor = [];
        foreach ($latest['contractor'] ?? [] as $event) {
            if ($event['is_deleted'] || (int) ($event['payload']['organization_id'] ?? 0) !== $organizationId) {
                continue;
            }
            $profileId = $profileByOrganization[(int) ($event['payload']['source_organization_id'] ?? 0)] ?? null;
            if ($profileId !== null) {
                $profileByContractor[(int) $event['subject_id']] = $profileId;
            }
        }
        $profileBySupplier = [];
        foreach ($latest['supplier'] ?? [] as $event) {
            if ($event['is_deleted'] || (int) ($event['payload']['organization_id'] ?? 0) !== $organizationId) {
                continue;
            }
            $metadata = $event['payload']['additional_info'] ?? null;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }
            if (! is_array($metadata)) {
                continue;
            }
            $profileId = $profilesById[(int) ($metadata['contractor_profile_id'] ?? 0)]
                ?? $profileByOrganization[(int) ($metadata['contractor_organization_id'] ?? 0)]
                ?? null;
            if ($profileId !== null) {
                $profileBySupplier[(int) $event['subject_id']] = $profileId;
            }
        }
        $resolvedProfileIds = array_values(array_unique([
            ...array_values($profileByContractor),
            ...array_values($profileBySupplier),
        ]));
        $categoryEvents = $resolvedProfileIds === []
            ? collect()
            : DB::table('contractor_scorecard_membership_events')
                ->where('subject_type', 'profile_category')
                ->where('observed_at', '<=', $asOf)
                ->whereIn(DB::raw("(payload->>'profile_id')::bigint"), $resolvedProfileIds)
                ->orderBy('subject_id')
                ->orderBy('observed_at')
                ->orderBy('id')
                ->get();
        $latest += $this->latest($categoryEvents);
        $categoriesByProfile = [];
        foreach ($latest['profile_category'] ?? [] as $event) {
            $profileId = (int) ($event['payload']['profile_id'] ?? 0);
            $categoryId = (int) ($event['payload']['category_id'] ?? 0);
            if (
                ! $event['is_deleted']
                && in_array($profileId, $resolvedProfileIds, true)
                && $categoryId > 0
            ) {
                $categoriesByProfile[$profileId][$categoryId] = true;
            }
        }
        ksort($profileByContractor, SORT_NUMERIC);
        ksort($profileBySupplier, SORT_NUMERIC);
        ksort($profileOrganizationById, SORT_NUMERIC);
        ksort($categoriesByProfile, SORT_NUMERIC);
        foreach ($categoriesByProfile as &$categories) {
            ksort($categories, SORT_NUMERIC);
        }
        unset($categories);
        $evidence = $coverage
            ->map(static fn (object $row): array => [
                'coverage_hash' => (string) $row->evidence_hash,
                'subject_type' => (string) $row->subject_type,
            ])
            ->all();
        foreach ($latest as $eventsBySubject) {
            foreach ($eventsBySubject as $event) {
                $evidence[] = [
                    'evidence_hash' => $event['evidence_hash'],
                    'event_id' => $event['id'],
                    'subject_id' => $event['subject_id'],
                    'subject_type' => $event['subject_type'],
                ];
            }
        }
        usort($evidence, static fn (array $left, array $right): int => [
            $left['subject_type'],
            $left['subject_id'] ?? 0,
            $left['event_id'] ?? 0,
        ] <=> [
            $right['subject_type'],
            $right['subject_id'] ?? 0,
            $right['event_id'] ?? 0,
        ]);

        return new ContractorMembershipEvidence(
            $profileByContractor,
            $profileBySupplier,
            $profileOrganizationById,
            $categoriesByProfile,
            $evidence,
            $coverageStartedAt->toISOString(),
        );
    }

    private function latest(Collection $events): array
    {
        $latest = [];
        foreach ($events as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
            if (! is_array($payload)) {
                throw new InvalidArgumentException('contractor_membership_evidence_invalid');
            }
            $subjectType = (string) $row->subject_type;
            $subjectId = (int) $row->subject_id;
            $latest[$subjectType][$subjectId] = [
                'evidence_hash' => (string) $row->evidence_hash,
                'id' => (int) $row->id,
                'is_deleted' => (bool) $row->is_deleted,
                'payload' => $payload,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
            ];
        }

        return $latest;
    }
}
