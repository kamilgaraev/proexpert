<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationFeatureGate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class ReportPublicationRegistryPostgresTest extends TestCase
{
    private bool $registryDatabaseInitialized = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('REPORT_PUBLICATION_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set REPORT_PUBLICATION_POSTGRES_TESTS=1 to run isolated publication registry tests.',
            );
        }
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }
        if (! $this->safeDatabase()) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->truncateRegistry();
        $this->registryDatabaseInitialized = true;
    }

    protected function tearDown(): void
    {
        if ($this->registryDatabaseInitialized) {
            self::assertTrue($this->safeDatabase(), 'Refusing to clean a non-test PostgreSQL database.');
            $this->truncateRegistry();
            $this->registryDatabaseInitialized = false;
        }
        parent::tearDown();
    }

    public function test_proof_and_events_are_append_only(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = $published->publicationIdentity?->publicationId;
        self::assertIsString($publicationId);

        $proofMutation = $this->queryException(static function () use ($publicationId): void {
            DB::table('report_publications')->where('id', $publicationId)->update([
                'proof_sha256' => str_repeat('f', 64),
            ]);
        });
        $eventId = DB::table('report_publication_events')->where('publication_id', $publicationId)->value('id');
        $eventMutation = $this->queryException(static function () use ($eventId): void {
            DB::table('report_publication_events')->where('id', $eventId)->delete();
        });

        self::assertSame('55000', $proofMutation->errorInfo[0] ?? null);
        self::assertSame('55000', $eventMutation->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_publications')->where('id', $publicationId)->count());
        self::assertSame(1, DB::table('report_publication_events')->where('publication_id', $publicationId)->count());
    }

    public function test_only_one_active_publication_exists_per_code(): void
    {
        [$registry, $eligible] = $this->registry();
        $registry->promote($eligible);
        $row = (array) DB::table('report_publications')->first();
        $row['id'] = (string) Str::ulid();
        $row['proof_sha256'] = str_repeat('f', 64);
        $proof = json_decode((string) $row['proof_json'], true, 512, JSON_THROW_ON_ERROR);
        $proof['binding_sha256'] = str_repeat('f', 64);
        $row['proof_json'] = json_encode($proof, JSON_THROW_ON_ERROR);
        $row['binding_sha256'] = str_repeat('f', 64);

        $exception = $this->queryException(static fn () => DB::table('report_publications')->insert($row));

        self::assertSame('23505', $exception->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_publications')->where('status', 'published')->count());
    }

    public function test_state_transition_writes_matching_event_and_outbox_at_commit(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;

        DB::transaction(static function () use ($publicationId): void {
            DB::table('report_publications')->where('id', $publicationId)->update([
                'status' => 'disabled',
                'disabled_at' => now(),
                'disabled_reason' => 'manual_disable',
                'disabled_by' => 'release-bot@most',
            ]);
            DB::statement('SET CONSTRAINTS report_publications_event_required IMMEDIATE');
        });

        self::assertSame('disabled', DB::table('report_publications')->where('id', $publicationId)->value('status'));
        self::assertSame(1, DB::table('report_publication_events')->where('publication_id', $publicationId)->where('event_type', 'disabled')->count());
        self::assertSame(1, DB::table('report_publication_outbox')->where('publication_id', $publicationId)->where('event_type', 'report_publication_disabled')->count());
    }

    public function test_preseeded_event_cannot_authorize_a_later_state_transition(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;
        $exception = $this->queryException(static function () use ($publicationId, $published): void {
            DB::table('report_publication_events')->insert([
                'id' => (string) Str::ulid(),
                'publication_id' => $publicationId,
                'event_type' => 'disabled',
                'actor_identity' => 'release-bot@most',
                'release_git_sha' => $published->publicationIdentity?->releaseGitSha,
                'payload_sha256' => str_repeat('f', 64),
                'occurred_at' => now(),
            ]);
        });

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame('published', DB::table('report_publications')->where('id', $publicationId)->value('status'));
        self::assertSame(0, DB::table('report_publication_events')->where('publication_id', $publicationId)->where('event_type', 'disabled')->count());

        DB::table('report_publications')->where('id', $publicationId)->update([
            'status' => 'disabled',
            'disabled_at' => now(),
            'disabled_reason' => 'manual_disable',
            'disabled_by' => 'release-bot@most',
        ]);
        self::assertSame(1, DB::table('report_publication_events')->where('publication_id', $publicationId)->where('event_type', 'disabled')->count());
    }

    public function test_raw_publication_insert_without_exact_feature_row_is_rejected_at_commit(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $oldPublicationId = (string) $published->publicationIdentity?->publicationId;
        $registry->disable($oldPublicationId, 'source_contract_revoked', 'release-bot@most');
        $row = (array) DB::table('report_publications')->where('id', $oldPublicationId)->first();
        $row['id'] = (string) Str::ulid();
        $row['status'] = 'published';
        $row['published_at'] = now();
        $row['disabled_at'] = null;
        $row['disabled_reason'] = null;
        $row['disabled_by'] = null;

        $exception = $this->queryException(static function () use ($row): void {
            DB::transaction(static function () use ($row): void {
                DB::table('report_publications')->insert($row);
            });
        });

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_publications')->count());
    }

    public function test_feature_state_is_bound_to_publication_and_proof(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;

        $exception = $this->queryException(static function () use ($publicationId): void {
            DB::table('report_publication_features')->where('publication_id', $publicationId)->update([
                'proof_sha256' => str_repeat('f', 64),
            ]);
        });

        self::assertSame('23503', $exception->errorInfo[0] ?? null);
        self::assertSame(
            $published->publicationIdentity?->proofHash->value,
            DB::table('report_publication_features')->where('publication_id', $publicationId)->value('proof_sha256'),
        );
    }

    public function test_equal_promotion_is_idempotent_and_unequal_promotion_conflicts(): void
    {
        [$registry, $eligible] = $this->registry();
        $first = $registry->promote($eligible);
        $same = $registry->promote($eligible);

        self::assertSame(
            $first->publicationIdentity?->publicationId,
            $same->publicationIdentity?->publicationId,
        );
        self::assertSame(1, DB::table('report_publications')->count());
        self::assertSame(1, DB::table('report_publication_events')->count());

        $differentFixture = ReportPublicationFixtureFactory::eligible('f');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_publication_promotion_conflict');
        $registry->promote($differentFixture['eligible']);
    }

    public function test_feature_modes_require_the_active_exact_publication_identity(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $identity = $published->publicationIdentity;
        self::assertNotNull($identity);
        $store = new EloquentReportPublicationFeatureStore(DB::connection());

        $canary = $store->configure(
            $identity,
            ReportPublicationFeatureMode::CANARY,
            [10],
            [20],
        );
        self::assertSame(ReportPublicationFeatureMode::CANARY, $canary->mode);
        self::assertSame([10], $store->current($identity->code)?->organizationAllowlist);
        self::assertSame(
            1,
            DB::table('report_publication_outbox')
                ->where('publication_id', $identity->publicationId)
                ->where('event_type', 'report_feature_configured')
                ->count(),
        );

        $stale = new ReportPublicationIdentity(
            $identity->publicationId,
            $identity->code,
            new Sha256Hash(str_repeat('f', 64)),
            $identity->releaseGitSha,
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_publication_feature_stale_identity');
        $store->configure($stale, ReportPublicationFeatureMode::ON, [], []);
    }

    public function test_raw_feature_mutation_writes_transactional_outbox(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;
        $before = DB::table('report_publication_outbox')->where('publication_id', $publicationId)->count();

        DB::table('report_publication_features')->where('publication_id', $publicationId)->update([
            'mode' => 'canary',
            'canary_organization_ids' => '[10]',
            'canary_user_ids' => '[]',
            'updated_at' => now()->addMicrosecond(),
        ]);

        self::assertSame(
            $before + 1,
            DB::table('report_publication_outbox')->where('publication_id', $publicationId)->count(),
        );
        self::assertSame(
            1,
            DB::table('report_publication_outbox')
                ->where('publication_id', $publicationId)
                ->where('event_type', 'report_feature_configured')
                ->where('payload_json->mode', 'canary')
                ->count(),
        );
    }

    public function test_identical_feature_retry_is_a_no_op(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $identity = $published->publicationIdentity;
        self::assertNotNull($identity);
        $store = new EloquentReportPublicationFeatureStore(DB::connection());
        $store->configure($identity, ReportPublicationFeatureMode::CANARY, [10], [20]);
        $updatedAt = DB::table('report_publication_features')->where('code', $identity->code)->value('updated_at');
        $outboxCount = DB::table('report_publication_outbox')->where('publication_id', $identity->publicationId)->count();

        $store->configure($identity, ReportPublicationFeatureMode::CANARY, [10], [20]);

        self::assertSame($updatedAt, DB::table('report_publication_features')->where('code', $identity->code)->value('updated_at'));
        self::assertSame($outboxCount, DB::table('report_publication_outbox')->where('publication_id', $identity->publicationId)->count());
    }

    public function test_persisted_canary_allowlist_denies_other_tenants(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $identity = $published->publicationIdentity;
        self::assertNotNull($identity);
        $store = new EloquentReportPublicationFeatureStore(DB::connection());
        $store->configure($identity, ReportPublicationFeatureMode::CANARY, [10], [20]);
        $configuration = $store->current($identity->code);
        self::assertNotNull($configuration);
        $gate = new ReportPublicationFeatureGate;

        self::assertTrue($gate->allows($configuration, $identity, 10, 999, 'run'));
        self::assertTrue($gate->allows($configuration, $identity, 999, 20, 'export'));
        self::assertFalse($gate->allows($configuration, $identity, 11, 21, 'run'));
        self::assertFalse($gate->allows($configuration, $identity, 10, 20, 'subscription'));
    }

    public function test_disable_writes_event_feature_state_and_outbox_atomically(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;

        $registry->disable($publicationId, 'source_contract_revoked', 'release-bot@most');

        self::assertSame('disabled', DB::table('report_publications')->where('id', $publicationId)->value('status'));
        self::assertSame('disabled', DB::table('report_publication_features')->where('publication_id', $publicationId)->value('mode'));
        self::assertSame(1, DB::table('report_publication_events')->where('publication_id', $publicationId)->where('event_type', 'disabled')->count());
        self::assertSame(1, DB::table('report_publication_outbox')->where('publication_id', $publicationId)->where('event_type', 'report_publication_disabled')->count());
        self::assertNull($registry->current($eligible->candidate->code));
    }

    public function test_raw_disable_writes_exact_event_feature_state_and_outbox_atomically(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;
        $identity = $published->publicationIdentity;
        self::assertNotNull($identity);
        $store = new EloquentReportPublicationFeatureStore(DB::connection());
        $store->configure($identity, ReportPublicationFeatureMode::ON, [], []);

        DB::transaction(static function () use ($publicationId): void {
            DB::table('report_publications')->where('id', $publicationId)->update([
                'status' => 'disabled',
                'disabled_at' => now(),
                'disabled_reason' => 'manual_disable',
                'disabled_by' => 'release-bot@most',
            ]);
        });

        $publication = DB::table('report_publications')->where('id', $publicationId)->first();
        $event = DB::table('report_publication_events')
            ->where('publication_id', $publicationId)
            ->where('event_type', 'disabled')
            ->first();
        $outbox = DB::table('report_publication_outbox')
            ->where('publication_id', $publicationId)
            ->where('event_type', 'report_publication_disabled')
            ->first();
        $expectedPayload = DB::connection()->selectOne(<<<'SQL'
            SELECT encode(
                sha256(convert_to(jsonb_build_object(
                    'actor_identity', disabled_by,
                    'disabled_at_utc', to_char(disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                    'publication_id', id,
                    'reason', disabled_reason
                )::text, 'UTF8')),
                'hex'
            ) AS payload_sha256
            FROM report_publications
            WHERE id = ?
            SQL, [$publicationId]);
        self::assertNotNull($publication);
        self::assertNotNull($event);
        self::assertNotNull($outbox);
        self::assertNotNull($expectedPayload);
        self::assertSame('release-bot@most', $event->actor_identity);
        self::assertSame($publication->release_git_sha, $event->release_git_sha);
        self::assertSame($publication->disabled_at, $event->occurred_at);
        self::assertSame($expectedPayload->payload_sha256, $event->payload_sha256);
        $outboxPayload = json_decode((string) $outbox->payload_json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($event->payload_sha256, $outboxPayload['payload_sha256'] ?? null);
        self::assertSame(1, DB::table('report_publication_events')->where('publication_id', $publicationId)->where('event_type', 'disabled')->count());
        self::assertSame(1, DB::table('report_publication_outbox')->where('publication_id', $publicationId)->where('event_type', 'report_publication_disabled')->count());
        self::assertSame('disabled', DB::table('report_publication_features')->where('publication_id', $publicationId)->value('mode'));
    }

    public function test_disabled_record_cannot_be_reenabled_and_new_proof_rebinds_feature_state(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $oldIdentity = $published->publicationIdentity;
        self::assertNotNull($oldIdentity);
        $registry->disable($oldIdentity->publicationId, 'source_contract_revoked', 'release-bot@most');

        $reenable = $this->queryException(static function () use ($oldIdentity): void {
            DB::table('report_publications')->where('id', $oldIdentity->publicationId)->update([
                'status' => 'published',
                'disabled_at' => null,
                'disabled_reason' => null,
            ]);
        });
        self::assertSame('55000', $reenable->errorInfo[0] ?? null);

        $different = ReportPublicationFixtureFactory::eligible('f');
        $next = $registry->promote($different['eligible']);
        self::assertNotSame($oldIdentity->publicationId, $next->publicationIdentity?->publicationId);
        self::assertSame('off', DB::table('report_publication_features')->value('mode'));
        self::assertSame(
            $next->publicationIdentity?->proofHash->value,
            DB::table('report_publication_features')->value('proof_sha256'),
        );
        self::assertSame(
            1,
            DB::table('report_publication_outbox')
                ->where('publication_id', $next->publicationIdentity?->publicationId)
                ->where('event_type', 'report_feature_configured')
                ->count(),
        );
    }

    public function test_concurrent_promotions_choose_exactly_one_active_proof(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-publication-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $children = [];
        $lockConnection = DB::connection();
        try {
            $lockConnection->beginTransaction();
            $lockConnection->select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['report-publication:project_portfolio_health'],
            );
            foreach ([0 => 'e', 1 => 'f'] as $index => $digit) {
                $children[] = $harness->spawn($index, static function () use ($digit): array {
                    $fixture = ReportPublicationFixtureFactory::eligible($digit);
                    $registry = new EloquentReportPublicationRegistry(
                        DB::connection(),
                        $fixture['eligibility_service'],
                        new ReportDefinitionFactory,
                    );
                    try {
                        $published = $registry->promote($fixture['eligible']);

                        return ['status' => 'promoted', 'id' => $published->publicationIdentity?->publicationId];
                    } catch (Throwable $exception) {
                        return [
                            'status' => 'conflict',
                            'error' => $exception::class,
                            'message' => $exception->getMessage(),
                        ];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForPostgresWait($lockConnection, $harness->waitForWorkerBackendPid(0), 'advisory');
            $harness->waitForPostgresWait($lockConnection, $harness->waitForWorkerBackendPid(1), 'advisory');
            $lockConnection->commit();
            $harness->waitForChildren($children, 30.0);
            $results = [$harness->result(0), $harness->result(1)];
            $statuses = array_column($results, 'status');
            sort($statuses, SORT_STRING);

            self::assertSame(['conflict', 'promoted'], $statuses);
            $conflict = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'conflict'))[0];
            self::assertSame(LogicException::class, $conflict['error']);
            self::assertSame('report_publication_promotion_conflict', $conflict['message']);
            self::assertSame(1, DB::table('report_publications')->where('status', 'published')->count());
            self::assertSame(1, DB::table('report_publication_events')->where('event_type', 'promoted')->count());
        } finally {
            if ($lockConnection->transactionLevel() > 0) {
                $lockConnection->rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_concurrent_equal_promotions_are_idempotent(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-publication-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $children = [];
        $lockConnection = DB::connection();
        try {
            $lockConnection->beginTransaction();
            $lockConnection->select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['report-publication:project_portfolio_health'],
            );
            foreach ([0, 1] as $index) {
                $children[] = $harness->spawn($index, static function (): array {
                    $fixture = ReportPublicationFixtureFactory::eligible();
                    $registry = new EloquentReportPublicationRegistry(
                        DB::connection(),
                        $fixture['eligibility_service'],
                        new ReportDefinitionFactory,
                    );
                    try {
                        $published = $registry->promote($fixture['eligible']);

                        return ['status' => 'promoted', 'id' => $published->publicationIdentity?->publicationId];
                    } catch (Throwable $exception) {
                        return [
                            'status' => 'conflict',
                            'error' => $exception::class,
                            'message' => $exception->getMessage(),
                        ];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForPostgresWait($lockConnection, $harness->waitForWorkerBackendPid(0), 'advisory');
            $harness->waitForPostgresWait($lockConnection, $harness->waitForWorkerBackendPid(1), 'advisory');
            $lockConnection->commit();
            $harness->waitForChildren($children, 30.0);
            $first = $harness->result(0);
            $second = $harness->result(1);

            self::assertSame('promoted', $first['status']);
            self::assertSame('promoted', $second['status']);
            self::assertSame($first['id'], $second['id']);
            self::assertSame(1, DB::table('report_publications')->where('status', 'published')->count());
            self::assertSame(1, DB::table('report_publication_events')->where('event_type', 'promoted')->count());
        } finally {
            if ($lockConnection->transactionLevel() > 0) {
                $lockConnection->rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_migration_round_trip_preserves_registry_contract(): void
    {
        $migration = require database_path('migrations/2026_08_01_000020_create_report_publication_registry.php');

        $migration->down();
        self::assertFalse(Schema::hasTable('report_publications'));
        self::assertFalse(Schema::hasTable('report_publication_features'));

        $migration->up();
        self::assertTrue(Schema::hasTable('report_publications'));
        self::assertTrue(Schema::hasTable('report_publication_features'));
    }

    private function registry(): array
    {
        $fixture = ReportPublicationFixtureFactory::eligible();

        return [
            new EloquentReportPublicationRegistry(
                DB::connection(),
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            ),
            $fixture['eligible'],
        ];
    }

    private function queryException(callable $operation): QueryException
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('Expected a PostgreSQL contract violation.');
    }

    private function safeDatabase(): bool
    {
        if (config('database.default') !== 'pgsql') {
            return false;
        }
        $row = DB::connection()->selectOne('SELECT current_database() AS database_name');
        $database = is_object($row) ? ($row->database_name ?? null) : null;

        return is_string($database) && preg_match('/_(?:test|testing)$/D', $database) === 1;
    }

    private function truncateRegistry(): void
    {
        DB::statement(
            'TRUNCATE report_publication_outbox, report_publication_features, report_publication_events, report_publications RESTART IDENTITY CASCADE',
        );
    }
}
