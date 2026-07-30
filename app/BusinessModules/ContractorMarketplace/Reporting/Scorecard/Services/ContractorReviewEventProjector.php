<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ContractorReviewEventProjector
{
    public function project(
        iterable $events,
        int $organizationId,
        array $projectIds,
        mixed $cohort,
    ): array {
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
                || (int) ($payload['reviewer_organization_id'] ?? 0) !== $organizationId
                || ($projectIds !== [] && ! in_array((int) ($payload['project_id'] ?? 0), $projectIds, true))
                || ! $this->matchesCohort($createdAt, $cohort)
            ) {
                continue;
            }
            $candidates[$reviewId] = [
                'event_id' => $event['event_id'],
                'evidence_hash' => $event['evidence_hash'],
                'observed_at' => $event['observed_at'],
                'membership_observed_at' => $event['observed_at'],
                'created_at' => $createdAt,
                'payload' => $payload,
            ];
        }
        ksort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    public static function identityEvents(array $candidates): array
    {
        return array_values(array_map(
            static fn (array $event): array => [
                'event_id' => $event['event_id'],
                'evidence_hash' => $event['evidence_hash'],
            ],
            $candidates,
        ));
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
