<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverExhaustedDocumentUnits;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class DocumentUnitDispatchRecoveryPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function exhausted_units_stop_dispatching_and_recover_without_changing_completed_or_excluded_pages(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $this->createTables();
            $sourceVersion = 'sha256:'.str_repeat('a', 64);
            $now = now()->toDateTimeImmutable();
            DB::table('estimate_generation_documents')->insert([
                'id' => 5,
                'source_version' => $sourceVersion,
                'status' => 'processing',
                'processing_control_status' => 'active',
                'meta' => '{}',
            ]);
            foreach ([
                [1, 'running', 3, 32767, 'processing'],
                [2, 'pending', 0, 32767, 'excluded'],
                [3, 'completed', 1, 32767, 'needs_review'],
                [4, 'running', 3, 10, 'processing'],
            ] as [$id, $status, $attempts, $dispatches, $pageStatus]) {
                DB::table('estimate_generation_processing_units')->insert([
                    'id' => $id,
                    'organization_id' => 10,
                    'project_id' => 20,
                    'session_id' => 30,
                    'document_id' => 5,
                    'source_version' => $sourceVersion,
                    'status' => $status,
                    'attempt_count' => $attempts,
                    'dispatch_attempt_count' => $dispatches,
                    'claim_token' => $status === 'running' ? '11111111-1111-4111-8111-111111111111' : null,
                    'lease_expires_at' => $status === 'running' ? $now->modify('-1 minute') : null,
                    'output_version' => $status === 'completed' ? $sourceVersion : null,
                    'output_count' => $status === 'completed' ? 1 : 0,
                    'completed_at' => $status === 'completed' ? $now : null,
                    'metadata' => '{}',
                ]);
                DB::table('estimate_generation_document_pages')->insert([
                    'id' => $id,
                    'processing_unit_id' => $id,
                    'organization_id' => 10,
                    'project_id' => 20,
                    'session_id' => 30,
                    'document_id' => 5,
                    'source_version' => $sourceVersion,
                    'status' => $pageStatus,
                ]);
            }

            $store = new EloquentDocumentUnitDispatchStore(DB::connection());
            self::assertSame([], $store->dueForRecovery($now, 16));
            self::assertFalse($store->dispatchIfAllowed(
                new DocumentUnitDispatchCandidate(2, $sourceVersion),
                $now,
                $now->modify('+5 minutes'),
                static function (): void {
                    self::fail('Exhausted unit was dispatched.');
                },
            ));

            $reconciler = new class implements DocumentUnitAggregateReconciler
            {
                public array $documentIds = [];

                public function reconcile(int $documentId, string $sourceVersion): void
                {
                    $this->documentIds[] = $documentId;
                }
            };
            $recovery = new RecoverExhaustedDocumentUnits(
                new EloquentDocumentUnitExhaustionHandler($reconciler),
                $reconciler,
                DB::connection(),
            );
            self::assertSame(4, $recovery->handle());

            foreach ([1, 4] as $id) {
                $unit = EstimateGenerationProcessingUnit::query()->findOrFail($id);
                self::assertSame('failed', $unit->status->value);
                self::assertSame('document_unit_attempts_exhausted', $unit->failure_code);
                self::assertNull($unit->claim_token);
                self::assertNull($unit->lease_expires_at);
                self::assertSame('failed', DB::table('estimate_generation_document_pages')->where('id', $id)->value('status'));
            }
            $pending = EstimateGenerationProcessingUnit::query()->findOrFail(2);
            self::assertSame('failed', $pending->status->value);
            self::assertSame('document_unit_dispatch_exhausted', $pending->failure_code);
            self::assertSame(32767, $pending->dispatch_attempt_count);
            self::assertSame('excluded', DB::table('estimate_generation_document_pages')->where('id', 2)->value('status'));
            self::assertSame('completed', EstimateGenerationProcessingUnit::query()->findOrFail(3)->status->value);
            self::assertSame('needs_review', DB::table('estimate_generation_document_pages')->where('id', 3)->value('status'));
            self::assertSame([5, 5, 5, 5], $reconciler->documentIds);
            self::assertSame([], $store->dueForRecovery($now, 16));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function final_dispatch_gets_its_recovery_window_before_the_unit_is_failed(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $this->createTables();
            $sourceVersion = 'sha256:'.str_repeat('b', 64);
            $now = now()->toDateTimeImmutable();
            DB::table('estimate_generation_documents')->insert([
                'id' => 5,
                'source_version' => $sourceVersion,
                'status' => 'processing',
                'processing_control_status' => 'active',
                'meta' => '{}',
            ]);
            DB::table('estimate_generation_processing_units')->insert([
                'id' => 1,
                'organization_id' => 10,
                'project_id' => 20,
                'session_id' => 30,
                'document_id' => 5,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'attempt_count' => 0,
                'dispatch_attempt_count' => 32766,
                'metadata' => '{}',
            ]);
            DB::table('estimate_generation_document_pages')->insert([
                'id' => 1,
                'processing_unit_id' => 1,
                'organization_id' => 10,
                'project_id' => 20,
                'session_id' => 30,
                'document_id' => 5,
                'source_version' => $sourceVersion,
                'status' => 'queued',
            ]);

            $store = new EloquentDocumentUnitDispatchStore(DB::connection());
            $candidate = $store->dueForRecovery($now, 16)[0];
            $dispatches = 0;
            self::assertTrue($store->dispatchIfAllowed(
                $candidate,
                $now,
                $now->modify('+5 minutes'),
                static function () use (&$dispatches): void {
                    $dispatches++;
                },
            ));
            self::assertSame(1, $dispatches);
            self::assertSame(32767, EstimateGenerationProcessingUnit::query()->findOrFail(1)->dispatch_attempt_count);

            $reconciler = new class implements DocumentUnitAggregateReconciler
            {
                public function reconcile(int $documentId, string $sourceVersion): void {}
            };
            $recovery = new RecoverExhaustedDocumentUnits(
                new EloquentDocumentUnitExhaustionHandler($reconciler),
                $reconciler,
                DB::connection(),
            );
            self::assertSame(0, $recovery->handle());
            self::assertSame('pending', EstimateGenerationProcessingUnit::query()->findOrFail(1)->status->value);
            DB::table('estimate_generation_processing_units')->where('id', 1)->update([
                'next_dispatch_at' => $now->modify('-1 second'),
            ]);
            self::assertSame(1, $recovery->handle());
            self::assertSame('failed', EstimateGenerationProcessingUnit::query()->findOrFail(1)->status->value);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function recovery_skips_locked_documents_and_units_and_retries_after_release(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $holderName = 'document_recovery_lock_holder';
        config(['database.connections.'.$holderName => config('database.connections.'.config('database.default'))]);
        DB::purge($holderName);
        $holder = DB::connection($holderName);
        $this->createTables();
        try {
            DB::statement("SET lock_timeout = '100ms'");
            $sourceVersion = 'sha256:'.str_repeat('c', 64);
            DB::table('estimate_generation_documents')->insert([
                'id' => 5, 'source_version' => $sourceVersion, 'status' => 'processing',
                'processing_control_status' => 'active', 'meta' => '{}',
            ]);
            DB::table('estimate_generation_processing_units')->insert([
                'id' => 1, 'organization_id' => 10, 'project_id' => 20, 'session_id' => 30,
                'document_id' => 5, 'source_version' => $sourceVersion, 'status' => 'running',
                'attempt_count' => 3, 'dispatch_attempt_count' => 10,
                'claim_token' => '11111111-1111-4111-8111-111111111111',
                'lease_expires_at' => now()->subMinute(), 'metadata' => '{}',
            ]);
            DB::table('estimate_generation_document_pages')->insert([
                'id' => 1, 'processing_unit_id' => 1, 'organization_id' => 10, 'project_id' => 20,
                'session_id' => 30, 'document_id' => 5, 'source_version' => $sourceVersion, 'status' => 'processing',
            ]);
            $reconciler = new class implements DocumentUnitAggregateReconciler
            {
                public array $documentIds = [];

                public function reconcile(int $documentId, string $sourceVersion): void
                {
                    $this->documentIds[] = $documentId;
                }
            };
            $recovery = new RecoverExhaustedDocumentUnits(
                new EloquentDocumentUnitExhaustionHandler($reconciler), $reconciler, DB::connection(),
            );
            $before = (array) DB::table('estimate_generation_processing_units')->where('id', 1)->first();

            foreach (['estimate_generation_documents' => 5, 'estimate_generation_processing_units' => 1] as $table => $id) {
                $holder->beginTransaction();
                try {
                    $holder->table($table)->where('id', $id)->lockForUpdate()->first();
                    self::assertSame(1, $recovery->handle());
                    self::assertSame($before, (array) DB::table('estimate_generation_processing_units')->where('id', 1)->first());
                    self::assertSame('processing', DB::table('estimate_generation_document_pages')->where('id', 1)->value('status'));
                    self::assertSame([], $reconciler->documentIds);
                } finally {
                    $holder->rollBack();
                }
            }

            self::assertSame(1, $recovery->handle());
            $unit = EstimateGenerationProcessingUnit::query()->findOrFail(1);
            self::assertSame('failed', $unit->status->value);
            self::assertSame('document_unit_attempts_exhausted', $unit->failure_code);
            self::assertSame(3, $unit->attempt_count);
            self::assertSame(10, $unit->dispatch_attempt_count);
            self::assertNull($unit->claim_token);
            self::assertNull($unit->lease_expires_at);
            self::assertSame('failed', DB::table('estimate_generation_document_pages')->where('id', 1)->value('status'));
            self::assertSame([5], $reconciler->documentIds);
        } finally {
            DB::statement('RESET lock_timeout');
            DB::purge($holderName);
            DB::unprepared('DROP TABLE estimate_generation_document_pages, estimate_generation_processing_units, estimate_generation_documents');
        }
    }

    private function createTables(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE estimate_generation_documents (
    id bigint PRIMARY KEY,
    source_version varchar(80),
    status varchar(40),
    processing_control_status varchar(20),
    units_reconciled_source_version varchar(80),
    meta jsonb DEFAULT '{}',
    created_at timestamptz,
    updated_at timestamptz
);
CREATE TABLE estimate_generation_processing_units (
    id bigint PRIMARY KEY,
    organization_id bigint,
    project_id bigint,
    session_id bigint,
    document_id bigint,
    source_version varchar(80),
    status varchar(20),
    attempt_count smallint DEFAULT 0,
    dispatch_attempt_count smallint DEFAULT 0,
    claim_token varchar(36),
    lease_expires_at timestamptz,
    output_version varchar(80),
    output_count integer DEFAULT 0,
    next_dispatch_at timestamptz,
    last_dispatched_at timestamptz,
    completed_at timestamptz,
    failed_at timestamptz,
    failure_code varchar(80),
    failure_fingerprint varchar(64),
    metadata jsonb DEFAULT '{}',
    created_at timestamptz,
    updated_at timestamptz
);
CREATE TABLE estimate_generation_document_pages (
    id bigint PRIMARY KEY,
    processing_unit_id bigint,
    organization_id bigint,
    project_id bigint,
    session_id bigint,
    document_id bigint,
    source_version varchar(80),
    status varchar(20),
    created_at timestamptz,
    updated_at timestamptz
);
SQL);
    }
}
