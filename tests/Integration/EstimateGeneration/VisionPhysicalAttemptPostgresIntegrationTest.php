<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VisionPhysicalAttemptPostgresIntegrationTest extends TestCase
{
    public function test_postgres_claim_lease_response_and_usage_transitions_are_atomic(): void
    {
        if (env('MOST_CI_POSTGRES_VISION_ATTEMPT_GATE') !== '1') {
            self::markTestSkipped('Dedicated PostgreSQL physical-attempt gate is CI-only.');
        }
        $database = DB::connection();
        if ($database->getDriverName() !== 'pgsql'
            || ! preg_match('/_(?:test|testing|contract)$/', (string) $database->getDatabaseName())) {
            self::fail('Disposable PostgreSQL test database is required.');
        }
        $store = new EloquentVisionPhysicalAttemptStore($database);
        $context = $this->context();
        $fingerprint = hash('sha256', 'request');
        $now = new DateTimeImmutable('2026-08-10T10:00:00+03:00');

        DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $context->attemptId)->delete();
        try {
            $first = $store->claim($context, $fingerprint, '11111111-1111-4111-8111-111111111111', $now, $now->modify('+1 minute'));
            $busy = $store->claim($context, $fingerprint, '22222222-2222-4222-8222-222222222222', $now, $now->modify('+1 minute'));
            self::assertSame('11111111-1111-4111-8111-111111111111', $first->ownerToken);
            self::assertSame('11111111-1111-4111-8111-111111111111', $busy->ownerToken);

            $store->markWireStarted(
                $context->attemptId,
                $fingerprint,
                '11111111-1111-4111-8111-111111111111',
                $now,
                $now->modify('+1 minute'),
            );
            $store->storeResponse(
                $context->attemptId,
                $fingerprint,
                '11111111-1111-4111-8111-111111111111',
                ['raw_body_base64' => 'e30='],
                'response_received',
                200,
                12,
                'model',
                ['available' => false],
            );
            $replay = $store->claim($context, $fingerprint, '33333333-3333-4333-8333-333333333333', $now, $now->modify('+1 minute'));
            self::assertSame('response_received', $replay->state);
            self::assertSame(['raw_body_base64' => 'e30='], $replay->responsePayload);

            $store->markUsageRecorded($context->attemptId, $fingerprint);
            self::assertSame('completed', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
            try {
                $store->markUsageRecorded($context->attemptId, $fingerprint);
                self::fail('Usage ledger completion collision was silently accepted.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation) {
            }
        } finally {
            DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $context->attemptId)->delete();
        }
    }

    public function test_only_stale_pre_wire_is_reclaimable_and_stale_wire_is_ambiguous(): void
    {
        if (env('MOST_CI_POSTGRES_VISION_ATTEMPT_GATE') !== '1') {
            self::markTestSkipped('Dedicated PostgreSQL physical-attempt gate is CI-only.');
        }
        $database = DB::connection();
        if ($database->getDriverName() !== 'pgsql'
            || ! preg_match('/_(?:test|testing|contract)$/', (string) $database->getDatabaseName())) {
            self::fail('Disposable PostgreSQL test database is required.');
        }
        $store = new EloquentVisionPhysicalAttemptStore($database);
        $fingerprint = hash('sha256', 'stale-request');
        $started = new DateTimeImmutable('2026-08-10T10:00:00+03:00');
        $stale = $this->context();
        $wire = new AiOperationContext(
            $stale->correlationId,
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            $stale->organizationId,
            $stale->projectId,
            $stale->sessionId,
            $stale->stage,
            $stale->operation,
            $stale->attemptOrdinal,
            $stale->documentId,
            $stale->pageId,
            $stale->unitId,
        );
        DB::table('estimate_generation_vision_physical_attempts')->whereIn('attempt_id', [$stale->attemptId, $wire->attemptId])->delete();
        try {
            $store->claim($stale, $fingerprint, '11111111-1111-4111-8111-111111111111', $started, $started->modify('+1 second'));
            $taken = $store->claim($stale, $fingerprint, '22222222-2222-4222-8222-222222222222', $started->modify('+2 seconds'), $started->modify('+1 minute'));
            self::assertSame('22222222-2222-4222-8222-222222222222', $taken->ownerToken);

            $store->claim($wire, $fingerprint, '11111111-1111-4111-8111-111111111111', $started, $started->modify('+1 second'));
            $store->markWireStarted($wire->attemptId, $fingerprint, '11111111-1111-4111-8111-111111111111', $started, $started->modify('+1 second'));
            $ambiguous = $store->claim($wire, $fingerprint, '22222222-2222-4222-8222-222222222222', $started->modify('+2 seconds'), $started->modify('+1 minute'));
            self::assertSame('ambiguous', $ambiguous->state);
        } finally {
            DB::table('estimate_generation_vision_physical_attempts')->whereIn('attempt_id', [$stale->attemptId, $wire->attemptId])->delete();
        }
    }

    private function context(): AiOperationContext
    {
        return new AiOperationContext(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            1,
            2,
            3,
            'understand_documents',
            'vision',
            1,
            4,
            5,
            6,
        );
    }
}
