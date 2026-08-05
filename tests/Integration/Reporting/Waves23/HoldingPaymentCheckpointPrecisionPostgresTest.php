<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableEventSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPaymentCheckpointPrecisionPostgresTest extends TestCase
{
    #[Test]
    public function runtime_checkpoint_validation_preserves_microsecond_binding(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $transactionId = random_int(800_000_000, 899_999_999);
            $startedAt = '2026-08-05 10:00:00.654321+00';
            $sourceHash = hash('sha256', 'microsecond-checkpoint-event');
            DB::table('holding_payment_transaction_event_versions')->insert([
                'transaction_id' => $transactionId,
                'payment_document_id' => $transactionId + 1,
                'organization_id' => $transactionId + 2,
                'project_id' => $transactionId + 3,
                'contract_id' => $transactionId + 4,
                'document_organization_id' => $transactionId + 2,
                'document_project_id' => $transactionId + 3,
                'contract_organization_id' => $transactionId + 2,
                'contract_project_id' => $transactionId + 3,
                'amount' => '10.00',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => '2026-08-05 00:00:00+00',
                'occurred_at' => $startedAt,
                'recorded_at' => $startedAt,
                'history_complete' => true,
                'source_hash' => $sourceHash,
            ]);
            DB::table('holding_payment_event_coverage_checkpoints')->insert([
                'started_at' => $startedAt,
                'source_max_transaction_id' => $transactionId,
                'source_count' => 1,
                'captured_count' => 1,
                'gap_count' => 0,
                'content_hash' => hash('sha256', $sourceHash),
            ]);

            $coverageStartedAt = (new HoldingPerformanceImmutableEventSource)->coverageStartedAt(
                new DateTimeImmutable('2026-08-05 00:00:00+00'),
                new DateTimeZone('UTC'),
            );

            self::assertSame(
                '2026-08-06 00:00:00.000000+00:00',
                $coverageStartedAt->format('Y-m-d H:i:s.uP'),
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function checkpoint_and_event_capture_preserve_distinct_microseconds(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $transactionId = random_int(700_000_000, 799_999_999);
            $recordedAt = [
                '2026-08-05 10:00:00.123456+00',
                '2026-08-05 10:00:00.123457+00',
            ];

            foreach ($recordedAt as $index => $timestamp) {
                DB::table('holding_payment_transaction_event_versions')->insert([
                    'transaction_id' => $transactionId,
                    'payment_document_id' => $transactionId + 1,
                    'organization_id' => $transactionId + 2,
                    'project_id' => $transactionId + 3,
                    'contract_id' => $transactionId + 4,
                    'document_organization_id' => $transactionId + 2,
                    'document_project_id' => $transactionId + 3,
                    'contract_organization_id' => $transactionId + 2,
                    'contract_project_id' => $transactionId + 3,
                    'amount' => '10.00',
                    'currency' => 'RUB',
                    'status' => 'completed',
                    'active' => $index === 0,
                    'recognized_at' => '2026-08-05 00:00:00+00',
                    'occurred_at' => $timestamp,
                    'recorded_at' => $timestamp,
                    'history_complete' => true,
                    'source_hash' => hash('sha256', 'precision-event-'.$index),
                ]);
            }

            $storedEvents = DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->orderBy('id')
                ->pluck('recorded_at')
                ->map(static fn (mixed $value): string => (new DateTimeImmutable((string) $value))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.uP'))
                ->all();

            self::assertSame([
                '2026-08-05 10:00:00.123456+00:00',
                '2026-08-05 10:00:00.123457+00:00',
            ], $storedEvents);

            DB::table('holding_payment_event_coverage_checkpoints')->insert([
                'started_at' => '2026-08-05 10:00:00.123458+00',
                'source_max_transaction_id' => $transactionId,
                'source_count' => 0,
                'captured_count' => 0,
                'gap_count' => 0,
                'content_hash' => hash('sha256', ''),
            ]);
            $storedCheckpoint = DB::table('holding_payment_event_coverage_checkpoints')
                ->latest('id')
                ->value('started_at');

            self::assertSame(
                '2026-08-05 10:00:00.123458+00:00',
                (new DateTimeImmutable((string) $storedCheckpoint))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.uP'),
            );
        } finally {
            DB::rollBack();
        }
    }
}
