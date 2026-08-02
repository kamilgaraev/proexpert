<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class HandoverReadinessPostgresTest extends TestCase
{
    public function test_database_rejects_wrong_family_and_non_prior_causation(): void
    {
        $this->requirePostgres();
        $organizationId = random_int(800_000_000, 809_999_999);
        $scopeId = random_int(810_000_000, 819_999_999);
        $sourceId = random_int(820_000_000, 829_999_999);
        $causeId = DB::table('handover_evidence_events')->insertGetId(
            $this->event($organizationId, $scopeId, $sourceId, 2, 'blocker_opened'),
        );

        foreach ([
            $this->event($organizationId, $scopeId, $sourceId, 1, 'blocker_resolved', $causeId),
            $this->event($organizationId, $scopeId, $sourceId, 3, 'finding_resolved', $causeId),
        ] as $invalid) {
            try {
                DB::table('handover_evidence_events')->insert($invalid);
                self::fail('Causation trigger must reject invalid source ordering or event family.');
            } catch (Throwable $exception) {
                self::assertStringContainsString('handover_evidence_causation_invalid', $exception->getMessage());
            }
        }
    }

    public function test_handover_events_are_append_only(): void
    {
        $this->requirePostgres();
        $organizationId = random_int(830_000_000, 839_999_999);
        $scopeId = random_int(840_000_000, 849_999_999);
        $sourceId = random_int(850_000_000, 859_999_999);
        $id = DB::table('handover_evidence_events')->insertGetId(
            $this->event($organizationId, $scopeId, $sourceId, 1, 'blocker_opened'),
        );

        try {
            DB::table('handover_evidence_events')->where('id', $id)->delete();
            self::fail('Append-only trigger must reject DELETE.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('reporting_fact_is_immutable', $exception->getMessage());
        }
    }

    private function event(
        int $organizationId,
        int $scopeId,
        int $sourceId,
        int $sourceVersion,
        string $eventType,
        ?int $causeId = null,
    ): array {
        return [
            'event_id' => fake()->uuid(),
            'organization_id' => $organizationId,
            'project_id' => 1,
            'acceptance_scope_id' => $scopeId,
            'event_type' => $eventType,
            'source_type' => 'quality_defect',
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'source_code' => 'quality_defect',
            'status' => 'open',
            'causation_event_id' => $causeId,
            'actor_id' => null,
            'occurred_at' => '2026-01-01T00:00:00Z',
            'evidence_hash' => hash('sha256', "{$organizationId}:{$scopeId}:{$sourceId}:{$sourceVersion}"),
            'evidence' => '{}',
            'created_at' => now(),
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL.');
        }
    }
}
