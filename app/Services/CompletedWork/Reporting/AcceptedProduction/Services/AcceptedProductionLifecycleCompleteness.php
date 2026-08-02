<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class AcceptedProductionLifecycleCompleteness
{
    public function inspect(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        Collection $events,
        ?array $universe = null,
    ): array {
        $candidates = $universe['candidates'] ?? null;
        if (! is_array($candidates) || ! array_is_list($candidates)) {
            throw new InvalidArgumentException('accepted_production_owner_universe_invalid');
        }
        $latestEvents = $events
            ->groupBy(static fn (ProductionAcceptanceEvent $event): string => self::key(
                (int) $event->performance_act_id,
                (string) $event->source_line_type,
                (int) $event->source_line_id,
            ))
            ->map(static fn (Collection $lineEvents): ?ProductionAcceptanceEvent => $lineEvents->last());

        $gaps = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)
                || ! is_numeric($candidate['performance_act_id'] ?? null)
                || ! is_string($candidate['source_line_type'] ?? null)
                || ! is_numeric($candidate['source_line_id'] ?? null)
                || ! is_string($candidate['event_type'] ?? null)
            ) {
                throw new InvalidArgumentException('accepted_production_owner_candidate_invalid');
            }
            $latest = $latestEvents->get(self::key(
                (int) $candidate['performance_act_id'],
                (string) $candidate['source_line_type'],
                (int) $candidate['source_line_id'],
            ));
            if (! $latest instanceof ProductionAcceptanceEvent
                || (string) $latest->event_type !== (string) $candidate['event_type']
            ) {
                $gaps[] = [
                    'owner_version_id' => (int) ($candidate['owner_version_id'] ?? 0),
                    'performance_act_id' => (int) $candidate['performance_act_id'],
                    'reason' => 'accepted_production_owner_history_unproven',
                    'source_line_id' => (int) $candidate['source_line_id'],
                    'source_line_type' => (string) $candidate['source_line_type'],
                ];
            }
        }
        foreach ($universe['orphan_events'] ?? [] as $orphan) {
            if (! is_array($orphan)) {
                throw new InvalidArgumentException('accepted_production_owner_universe_invalid');
            }
            $gaps[] = [
                'event_id' => (int) ($orphan['event_id'] ?? 0),
                'performance_act_id' => (int) ($orphan['performance_act_id'] ?? 0),
                'reason' => 'accepted_production_owner_version_missing',
                'source_line_id' => (int) ($orphan['source_line_id'] ?? 0),
                'source_line_type' => (string) ($orphan['source_line_type'] ?? ''),
            ];
        }
        foreach ($universe['legacy_gaps'] ?? [] as $legacyGap) {
            if (! is_array($legacyGap)) {
                throw new InvalidArgumentException('accepted_production_owner_universe_invalid');
            }
            $gaps[] = [
                'ledger_id' => (int) ($legacyGap['ledger_id'] ?? 0),
                'performance_act_id' => (int) ($legacyGap['performance_act_id'] ?? 0),
                'reason' => (string) ($legacyGap['reason'] ?? 'historical_membership_unprovable'),
            ];
        }

        return $gaps;
    }

    public function assertComplete(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        Collection $events,
        ?array $universe = null,
    ): void {
        if ($this->inspect($scope, $asOf, $events, $universe) !== []) {
            throw new InvalidArgumentException('accepted_production_owner_history_unproven');
        }
    }

    private static function key(int $actId, string $sourceLineType, int $sourceLineId): string
    {
        return implode(':', [$actId, $sourceLineType, $sourceLineId]);
    }
}
