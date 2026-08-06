<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\CurrencyCode;
use App\Support\Reporting\DeterministicObjectSpool;
use Generator;
use stdClass;

final readonly class AcceptedProductionUniverseStream
{
    public function __construct(
        private DeterministicObjectSpool $entries,
        private DeterministicObjectSpool $gaps,
        public int $eventWatermark,
        public int $ownerWatermark,
        public int $ownerMemberWatermark,
        public AcceptedProductionHistoryBoundary $historyBoundary,
    ) {}

    public function entries(): Generator
    {
        foreach ($this->entries->items() as $entry) {
            if ($entry instanceof AcceptedProductionUniverseEntry) {
                yield $entry;
            }
        }
    }

    public function gaps(): Generator
    {
        foreach ($this->gaps->items() as $gap) {
            if ($gap instanceof stdClass) {
                yield (array) $gap;
            }
        }
    }

    public function eligibleCount(): int
    {
        return $this->entries->count() + $this->gaps->count();
    }

    public function gapCount(): int
    {
        $count = $this->gaps->count();
        foreach ($this->entries() as $entry) {
            $event = $entry->latestEvent();
            if ($event === null
                || (string) $event->event_type !== (string) $entry->candidate['event_type']
                || $event->approved_rate_minor === null
                || CurrencyCode::tryFrom((string) $event->currency) === null
                || trim((string) $event->currency_source) === ''
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function updateSourceHash(mixed $context): void
    {
        hash_update($context, '{"entries":');
        $this->entries->updateCanonicalArrayHash($context);
        hash_update($context, ',"gaps":');
        $this->gaps->updateCanonicalArrayHash($context);
        hash_update($context, ',"history_boundary":');
        hash_update($context, CanonicalJson::encode($this->historyBoundary->canonicalIdentity()));
        hash_update($context, '}');
    }
}
