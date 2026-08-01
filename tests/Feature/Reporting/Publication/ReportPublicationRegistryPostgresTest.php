<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->truncateRegistry();
    }

    protected function tearDown(): void
    {
        if (getenv('REPORT_PUBLICATION_POSTGRES_TESTS') === '1'
            && config('database.default') === 'pgsql') {
            $this->truncateRegistry();
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

    public function test_state_transition_requires_matching_event_at_commit(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;

        $exception = $this->queryException(static function () use ($publicationId): void {
            DB::transaction(static function () use ($publicationId): void {
                DB::table('report_publications')->where('id', $publicationId)->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'disabled_reason' => 'manual_disable',
                ]);
                DB::statement('SET CONSTRAINTS report_publications_event_required IMMEDIATE');
            });
        });

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame('published', DB::table('report_publications')->where('id', $publicationId)->value('status'));
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
    }

    public function test_concurrent_promotions_choose_exactly_one_active_proof(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-publication-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $children = [];
        try {
            foreach ([0 => 'e', 1 => 'f'] as $index => $digit) {
                $children[] = $harness->spawn($index, static function () use ($digit): array {
                    $fixture = ReportPublicationFixtureFactory::eligible($digit);
                    $registry = new EloquentReportPublicationRegistry(
                        DB::connection(),
                        $fixture['registry'],
                    );
                    try {
                        $published = $registry->promote($fixture['eligible']);

                        return ['status' => 'promoted', 'id' => $published->publicationIdentity?->publicationId];
                    } catch (Throwable $exception) {
                        return ['status' => 'conflict', 'error' => $exception::class];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForChildren($children, 30.0);
            $statuses = [$harness->result(0)['status'], $harness->result(1)['status']];
            sort($statuses, SORT_STRING);

            self::assertSame(['conflict', 'promoted'], $statuses);
            self::assertSame(1, DB::table('report_publications')->where('status', 'published')->count());
            self::assertSame(1, DB::table('report_publication_events')->where('event_type', 'promoted')->count());
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    private function registry(): array
    {
        $fixture = ReportPublicationFixtureFactory::eligible();

        return [
            new EloquentReportPublicationRegistry(DB::connection(), $fixture['registry']),
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

    private function truncateRegistry(): void
    {
        DB::statement(
            'TRUNCATE report_publication_outbox, report_publication_features, report_publication_events, report_publications RESTART IDENTITY CASCADE',
        );
    }
}
