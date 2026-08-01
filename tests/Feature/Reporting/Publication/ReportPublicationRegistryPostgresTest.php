<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationFeatureGate;
use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    public function test_runtime_role_cannot_forge_publication_event_or_outbox_rows(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;

        foreach ([
            'report_publications',
            'report_publication_events',
            'report_publication_features',
            'report_publication_outbox',
        ] as $table) {
            $exception = $this->queryException(static function () use ($table, $publicationId): void {
                DB::transaction(static function () use ($table, $publicationId): void {
                    DB::statement('SET LOCAL ROLE most_report_publication_runtime');
                    if ($table === 'report_publications') {
                        $row = (array) DB::table($table)->where('id', $publicationId)->first();
                        $row['id'] = (string) Str::ulid();
                        DB::table($table)->insert($row);

                        return;
                    }
                    if ($table === 'report_publication_events') {
                        DB::table($table)->insert([
                            'id' => (string) Str::ulid(),
                            'publication_id' => $publicationId,
                            'event_type' => 'disabled',
                            'actor_identity' => 'forged@most',
                            'release_git_sha' => str_repeat('a', 40),
                            'payload_sha256' => str_repeat('f', 64),
                            'occurred_at' => now(),
                        ]);

                        return;
                    }
                    if ($table === 'report_publication_features') {
                        $row = (array) DB::table($table)->where('publication_id', $publicationId)->first();
                        $row['code'] = 'forged_publication';
                        DB::table($table)->insert($row);

                        return;
                    }
                    DB::table($table)->insert([
                        'id' => (string) Str::ulid(),
                        'publication_id' => $publicationId,
                        'event_type' => 'report_publication_promoted',
                        'deduplication_key' => 'forged:'.$publicationId,
                        'payload_json' => '{}',
                        'created_at' => now(),
                        'delivered_at' => null,
                    ]);
                });
            });

            self::assertSame('42501', $exception->errorInfo[0] ?? null, $table);
        }
    }

    public function test_issuer_role_promotes_only_through_the_owned_admission_function(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $registry = new EloquentReportPublicationRegistry(
            DB::connection(),
            $fixture['eligibility_service'],
            new ReportDefinitionFactory,
        );

        $published = DB::transaction(static function () use ($registry, $fixture) {
            DB::statement('SET LOCAL ROLE most_report_publication_issuer');

            return $registry->promote($fixture['eligible']);
        });

        self::assertSame($fixture['eligible']->proofHash->value, $published->publicationIdentity?->proofHash->value);
        self::assertSame(
            hash('sha256', $fixture['eligible']->releaseArtifactBytes),
            DB::table('report_publications')->value('release_artifact_sha256'),
        );
    }

    public function test_non_superuser_issuer_principal_can_only_use_admission_function_without_owner_bypass(): void
    {
        $connectionName = 'report-publication-issuer-principal';
        $login = 'most_report_publication_test_issuer_login';
        $password = 'publication-test-only-password';
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = 'most_report_publication_test_issuer_login'
                ) THEN
                    CREATE ROLE most_report_publication_test_issuer_login
                        LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
                        PASSWORD 'publication-test-only-password';
                END IF;
                GRANT most_report_publication_issuer TO most_report_publication_test_issuer_login;
            END;
            $$;
            SQL);
        $configuration = config('database.connections.pgsql');
        self::assertIsArray($configuration);
        config(["database.connections.{$connectionName}" => array_replace($configuration, [
            'username' => $login,
            'password' => $password,
        ])]);
        $connection = DB::connection($connectionName);

        try {
            $identity = $connection->selectOne(<<<'SQL'
                SELECT current_user AS current_user,
                    rolsuper,
                    rolcreaterole,
                    rolcreatedb,
                    rolbypassrls,
                    pg_has_role(current_user, 'most_report_publication_owner', 'MEMBER') AS owner_member
                FROM pg_roles
                WHERE rolname = current_user
                SQL);
            self::assertSame($login, $identity->current_user ?? null);
            self::assertFalse((bool) ($identity->rolsuper ?? true));
            self::assertFalse((bool) ($identity->rolcreaterole ?? true));
            self::assertFalse((bool) ($identity->rolcreatedb ?? true));
            self::assertFalse((bool) ($identity->rolbypassrls ?? true));
            self::assertFalse((bool) ($identity->owner_member ?? true));

            $fixture = ReportPublicationFixtureFactory::eligible();
            $registry = new EloquentReportPublicationRegistry(
                $connection,
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            );
            $published = $registry->promote($fixture['eligible']);
            self::assertSame(
                $fixture['eligible']->proofHash->value,
                $published->publicationIdentity?->proofHash->value,
            );

            $eventException = $this->queryException(static fn () => $connection
                ->table('report_publication_events')
                ->insert([
                    'id' => (string) Str::ulid(),
                    'publication_id' => $published->publicationIdentity?->publicationId,
                    'event_type' => 'disabled',
                    'actor_identity' => 'forged@most',
                    'release_git_sha' => str_repeat('a', 40),
                    'payload_sha256' => str_repeat('f', 64),
                    'occurred_at' => now(),
                ]));
            $ownerException = $this->queryException(
                static fn () => $connection->statement('SET ROLE most_report_publication_owner'),
            );
            self::assertSame('42501', $eventException->errorInfo[0] ?? null);
            self::assertSame('42501', $ownerException->errorInfo[0] ?? null);
        } finally {
            DB::purge($connectionName);
            DB::statement('REVOKE most_report_publication_issuer FROM most_report_publication_test_issuer_login');
            DB::statement('DROP ROLE IF EXISTS most_report_publication_test_issuer_login');
        }
    }

    public function test_non_superuser_issuer_principal_cannot_redirect_registry_through_temporary_tables(): void
    {
        $connectionName = 'report-publication-issuer-shadow-principal';
        $login = 'most_report_publication_test_issuer_shadow_login';
        $password = 'publication-shadow-test-only-password';
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = 'most_report_publication_test_issuer_shadow_login'
                ) THEN
                    CREATE ROLE most_report_publication_test_issuer_shadow_login
                        LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
                        PASSWORD 'publication-shadow-test-only-password';
                END IF;
                GRANT most_report_publication_issuer TO most_report_publication_test_issuer_shadow_login;
            END;
            $$;
            SQL);
        $connection = $this->principalConnection($connectionName, $login, $password);

        try {
            $principal = $connection->selectOne(<<<'SQL'
                SELECT current_user AS current_user,
                    rolsuper,
                    pg_has_role(current_user, 'most_report_publication_owner', 'MEMBER') AS owner_member,
                    pg_has_role(current_user, 'most_report_publication_issuer', 'MEMBER') AS issuer_member
                FROM pg_roles
                WHERE rolname = current_user
                SQL);
            self::assertSame($login, $principal->current_user ?? null);
            self::assertFalse((bool) ($principal->rolsuper ?? true));
            self::assertFalse((bool) ($principal->owner_member ?? true));
            self::assertTrue((bool) ($principal->issuer_member ?? false));

            $fixture = ReportPublicationFixtureFactory::eligible();
            $seedRegistry = new EloquentReportPublicationRegistry(
                DB::connection(),
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            );
            $seed = $seedRegistry->promote($fixture['eligible']);
            $shadowPublicationId = $seed->publicationIdentity?->publicationId;
            self::assertIsString($shadowPublicationId);

            foreach ([
                'report_publications',
                'report_publication_events',
                'report_publication_features',
                'report_publication_outbox',
            ] as $table) {
                $connection->statement(
                    "CREATE TEMPORARY TABLE {$table} "
                    ."(LIKE public.{$table} INCLUDING ALL) ON COMMIT PRESERVE ROWS",
                );
            }
            $connection->statement(
                'INSERT INTO pg_temp.report_publications SELECT * FROM public.report_publications',
            );
            $connection->statement(
                'INSERT INTO pg_temp.report_publication_features SELECT * FROM public.report_publication_features',
            );
            $connection->statement(<<<'SQL'
                GRANT SELECT, INSERT, UPDATE, DELETE
                    ON TABLE pg_temp.report_publications, pg_temp.report_publication_events,
                        pg_temp.report_publication_features, pg_temp.report_publication_outbox
                    TO most_report_publication_owner
                SQL);
            $this->truncateRegistry();

            $registry = new EloquentReportPublicationRegistry(
                $connection,
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            );
            $published = $registry->promote($fixture['eligible']);
            $publicationId = $published->publicationIdentity?->publicationId;
            self::assertIsString($publicationId);
            self::assertNotSame($shadowPublicationId, $publicationId);
            self::assertSame(
                $publicationId,
                $registry->current($fixture['eligible']->candidate->code)?->publicationIdentity?->publicationId,
            );
            $history = iterator_to_array($registry->history($fixture['eligible']->candidate->code), false);

            self::assertCount(1, $history);
            self::assertSame($publicationId, $history[0]->identity->publicationId);
            self::assertSame(1, $this->tableCount('public.report_publications'));
            self::assertSame(1, $this->tableCount('public.report_publication_features'));
            $event = DB::table('public.report_publication_events')->first();
            self::assertSame($publicationId, $event->publication_id ?? null);
            self::assertSame('promoted', $event->event_type ?? null);
            self::assertSame(1, $this->tableCount('public.report_publication_outbox'));
            self::assertSame(1, $this->connectionTableCount($connection, 'pg_temp.report_publications'));
            self::assertSame(0, $this->connectionTableCount($connection, 'pg_temp.report_publication_events'));
            self::assertSame(0, $this->connectionTableCount($connection, 'pg_temp.report_publication_outbox'));
        } finally {
            DB::purge($connectionName);
            DB::statement(
                'REVOKE most_report_publication_issuer FROM most_report_publication_test_issuer_shadow_login',
            );
            DB::statement('DROP ROLE IF EXISTS most_report_publication_test_issuer_shadow_login');
        }
    }

    public function test_non_superuser_operator_principal_configures_and_disables_only_through_admission_functions(): void
    {
        $connectionName = 'report-publication-operator-principal';
        $login = 'most_report_publication_test_operator_login';
        $password = 'publication-operator-test-only-password';
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = 'most_report_publication_test_operator_login'
                ) THEN
                    CREATE ROLE most_report_publication_test_operator_login
                        LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
                        PASSWORD 'publication-operator-test-only-password';
                END IF;
                GRANT most_report_publication_operator TO most_report_publication_test_operator_login;
            END;
            $$;
            SQL);
        $connection = $this->principalConnection($connectionName, $login, $password);

        try {
            $identity = $connection->selectOne(<<<'SQL'
                SELECT current_user AS current_user,
                    rolsuper,
                    rolcreaterole,
                    rolcreatedb,
                    rolbypassrls,
                    pg_has_role(current_user, 'most_report_publication_owner', 'MEMBER') AS owner_member,
                    pg_has_role(current_user, 'most_report_publication_issuer', 'MEMBER') AS issuer_member,
                    pg_has_role(current_user, 'most_report_publication_operator', 'MEMBER') AS operator_member
                FROM pg_roles
                WHERE rolname = current_user
                SQL);
            self::assertSame($login, $identity->current_user ?? null);
            self::assertFalse((bool) ($identity->rolsuper ?? true));
            self::assertFalse((bool) ($identity->rolcreaterole ?? true));
            self::assertFalse((bool) ($identity->rolcreatedb ?? true));
            self::assertFalse((bool) ($identity->rolbypassrls ?? true));
            self::assertFalse((bool) ($identity->owner_member ?? true));
            self::assertFalse((bool) ($identity->issuer_member ?? true));
            self::assertTrue((bool) ($identity->operator_member ?? false));

            $fixture = ReportPublicationFixtureFactory::eligible();
            $seedRegistry = new EloquentReportPublicationRegistry(
                DB::connection(),
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            );
            $published = $seedRegistry->promote($fixture['eligible']);
            $publicationIdentity = $published->publicationIdentity;
            self::assertNotNull($publicationIdentity);
            $store = new EloquentReportPublicationFeatureStore($connection);
            $registry = new EloquentReportPublicationRegistry(
                $connection,
                $fixture['eligibility_service'],
                new ReportDefinitionFactory,
            );

            $configuration = $store->configure(
                $publicationIdentity,
                ReportPublicationFeatureMode::CANARY,
                [10],
                [20],
            );
            self::assertSame(ReportPublicationFeatureMode::CANARY, $configuration->mode);
            self::assertSame('canary', DB::table('report_publication_features')->value('mode'));

            $stale = new ReportPublicationIdentity(
                $publicationIdentity->publicationId,
                $publicationIdentity->code,
                new Sha256Hash(str_repeat('f', 64)),
                $publicationIdentity->releaseGitSha,
            );
            $staleException = $this->logicException(
                static fn () => $store->configure($stale, ReportPublicationFeatureMode::ON, [], []),
            );
            self::assertSame('report_publication_feature_stale_identity', $staleException->getMessage());

            foreach ([
                static fn () => $connection->table('public.report_publications')
                    ->where('id', $publicationIdentity->publicationId)
                    ->update(['disabled_reason' => 'forged']),
                static fn () => $connection->table('public.report_publication_features')
                    ->where('publication_id', $publicationIdentity->publicationId)
                    ->update(['mode' => 'on']),
                static fn () => $connection->table('public.report_publication_events')->insert([
                    'id' => (string) Str::ulid(),
                    'publication_id' => $publicationIdentity->publicationId,
                    'event_type' => 'disabled',
                    'actor_identity' => 'forged@most',
                    'release_git_sha' => $publicationIdentity->releaseGitSha,
                    'payload_sha256' => str_repeat('f', 64),
                    'occurred_at' => now(),
                ]),
                static fn () => $connection->table('public.report_publication_outbox')->insert([
                    'id' => (string) Str::ulid(),
                    'publication_id' => $publicationIdentity->publicationId,
                    'event_type' => 'report_publication_disabled',
                    'deduplication_key' => 'forged:'.$publicationIdentity->publicationId,
                    'payload_json' => '{}',
                    'created_at' => now(),
                    'delivered_at' => null,
                ]),
            ] as $mutation) {
                $exception = $this->queryException($mutation);
                self::assertSame('42501', $exception->errorInfo[0] ?? null);
            }
            $ownerException = $this->queryException(
                static fn () => $connection->statement('SET ROLE most_report_publication_owner'),
            );
            self::assertSame('42501', $ownerException->errorInfo[0] ?? null);

            $registry->disable(
                $publicationIdentity->publicationId,
                'source_contract_revoked',
                'release-bot@most',
            );
            self::assertSame(
                'disabled',
                DB::table('report_publications')->where('id', $publicationIdentity->publicationId)->value('status'),
            );
            self::assertSame(
                'disabled',
                DB::table('report_publication_features')->where('publication_id', $publicationIdentity->publicationId)
                    ->value('mode'),
            );
            self::assertSame(
                1,
                DB::table('report_publication_events')
                    ->where('publication_id', $publicationIdentity->publicationId)
                    ->where('event_type', 'disabled')
                    ->count(),
            );
            $repeatException = $this->logicException(
                static fn () => $registry->disable(
                    $publicationIdentity->publicationId,
                    'source_contract_revoked',
                    'release-bot@most',
                ),
            );
            self::assertSame('report_publication_not_active', $repeatException->getMessage());
        } finally {
            DB::purge($connectionName);
            DB::statement(
                'REVOKE most_report_publication_operator FROM most_report_publication_test_operator_login',
            );
            DB::statement('DROP ROLE IF EXISTS most_report_publication_test_operator_login');
        }
    }

    public function test_issuer_admission_rejects_null_release_signature_and_evidence(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $artifact = json_decode(
            $fixture['eligible']->releaseArtifactBytes,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($artifact);
        $artifact['signature'] = null;
        $artifact['evidence'] = null;
        $artifact['provenance'] = null;

        $exception = $this->queryException(fn () => $this->promoteThroughAdmissionFunction(
            $fixture['eligible'],
            CanonicalJson::encode($artifact),
        ));

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(0, DB::table('report_publications')->count());
    }

    public function test_issuer_admission_rejects_future_release_timestamp(): void
    {
        $releaseAt = new DateTimeImmutable('+1 day');
        $fixture = ReportPublicationFixtureFactory::eligible(
            'e',
            $releaseAt,
            $releaseAt->modify('-1 microsecond'),
        );

        $exception = $this->queryException(
            fn () => $this->promoteThroughAdmissionFunction($fixture['eligible']),
        );

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(0, DB::table('report_publications')->count());
    }

    public function test_issuer_admission_rejects_non_integer_provenance_run_attempt(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $artifact = json_decode(
            $fixture['eligible']->releaseArtifactBytes,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($artifact);
        $artifact['provenance']['run_attempt'] = '1';

        $exception = $this->queryException(fn () => $this->promoteThroughAdmissionFunction(
            $fixture['eligible'],
            CanonicalJson::encode($artifact),
        ));

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(0, DB::table('report_publications')->count());
    }

    public function test_temp_shadow_cannot_redirect_security_definer_transition_artifacts(): void
    {
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE report_publication_events
                (LIKE public.report_publication_events INCLUDING ALL)
                ON COMMIT PRESERVE ROWS
            SQL);
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE report_publication_outbox
                (LIKE public.report_publication_outbox INCLUDING ALL)
                ON COMMIT PRESERVE ROWS
            SQL);
        DB::statement(<<<'SQL'
            GRANT SELECT, INSERT, UPDATE, DELETE
                ON TABLE pg_temp.report_publication_events, pg_temp.report_publication_outbox
                TO most_report_publication_owner
            SQL);
        DB::statement('DISCARD PLANS');

        try {
            $fixture = ReportPublicationFixtureFactory::eligible();
            $this->promoteThroughAdmissionFunction($fixture['eligible']);

            self::assertSame(1, $this->tableCount('public.report_publication_events'));
            self::assertSame(1, $this->tableCount('public.report_publication_outbox'));
            self::assertSame(0, $this->tableCount('pg_temp.report_publication_events'));
            self::assertSame(0, $this->tableCount('pg_temp.report_publication_outbox'));
        } finally {
            DB::statement('DROP TABLE IF EXISTS pg_temp.report_publication_outbox');
            DB::statement('DROP TABLE IF EXISTS pg_temp.report_publication_events');
        }
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

    public function test_raw_backdated_publication_is_rejected(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $oldPublicationId = (string) $published->publicationIdentity?->publicationId;
        $registry->disable($oldPublicationId, 'source_contract_revoked', 'release-bot@most');
        $row = (array) DB::table('report_publications')->where('id', $oldPublicationId)->first();
        $row['id'] = (string) Str::ulid();
        $row['status'] = 'published';
        $row['published_at'] = (new \DateTimeImmutable((string) $row['published_at']))->modify('+1 microsecond');
        $row['disabled_at'] = null;
        $row['disabled_reason'] = null;
        $row['disabled_by'] = null;

        $exception = $this->queryException(static function () use ($row): void {
            DB::table('report_publications')->insert($row);
        });

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_publications')->count());
    }

    public function test_disabled_same_proof_replay_is_rejected_before_insert(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;
        $registry->disable($publicationId, 'source_contract_revoked', 'release-bot@most');

        try {
            $registry->promote($eligible);
            self::fail('Disabled proof replay must be rejected by the application gate.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('report_publication_ineligible', $exception->getMessage());
        }
        self::assertSame(1, DB::table('report_publications')->count());
        self::assertSame('disabled', DB::table('report_publications')->value('status'));
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

    public function test_feature_update_fails_fast_while_publication_is_locked(): void
    {
        [$registry, $eligible] = $this->registry();
        $published = $registry->promote($eligible);
        $publicationId = (string) $published->publicationIdentity?->publicationId;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-publication-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $lockConnection = $harness->independentConnection('report-publication-disable-lock');
        $children = [];
        try {
            $lockConnection->beginTransaction();
            $lockConnection->select(
                'SELECT id FROM report_publications WHERE id = ? FOR UPDATE',
                [$publicationId],
            );
            $children[] = $harness->spawn(2, static function () use ($publicationId): array {
                try {
                    DB::table('report_publication_features')->where('publication_id', $publicationId)->update([
                        'mode' => 'canary',
                        'canary_organization_ids' => '[10]',
                        'canary_user_ids' => '[]',
                        'updated_at' => now()->addMicrosecond(),
                    ]);

                    return ['status' => 'updated'];
                } catch (QueryException $exception) {
                    return [
                        'status' => 'lock_rejected',
                        'sqlstate' => $exception->errorInfo[0] ?? null,
                    ];
                }
            });
            $harness->release(2);
            $harness->waitForChildren($children, 5.0);
            $result = $harness->result(2);

            self::assertSame('lock_rejected', $result['status']);
            self::assertSame('55P03', $result['sqlstate']);
            $lockConnection->rollBack();
            $registry->disable($publicationId, 'source_contract_revoked', 'release-bot@most');
            self::assertSame(
                'disabled',
                DB::table('report_publication_features')->where('publication_id', $publicationId)->value('mode'),
            );
        } finally {
            if ($lockConnection->transactionLevel() > 0) {
                $lockConnection->rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
            DB::purge('report-publication-disable-lock');
        }
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

        $disabledAt = DB::table('report_publications')
            ->where('id', $oldIdentity->publicationId)
            ->value('disabled_at');
        self::assertIsString($disabledAt);
        $nextReleaseAt = (new \DateTimeImmutable($disabledAt))->modify('+1 second');
        $different = ReportPublicationFixtureFactory::eligible(
            'e',
            $nextReleaseAt,
            $nextReleaseAt->modify('-1 microsecond'),
        );
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

    private function promoteThroughAdmissionFunction(
        EligibleReportPublication $eligible,
        ?string $releaseArtifactBytes = null,
    ): void {
        $artifactBytes = $releaseArtifactBytes ?? $eligible->releaseArtifactBytes;
        $artifact = json_decode($artifactBytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($artifact);
        $proof = $eligible->proof->payload();

        DB::transaction(static function () use ($artifact, $artifactBytes, $eligible, $proof): void {
            DB::statement('SET LOCAL ROLE most_report_publication_issuer');
            DB::select(<<<'SQL'
                SELECT report_publication_promote(
                    ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, CAST(? AS timestamptz)
                )
                SQL, [
                (string) Str::ulid(),
                $eligible->candidate->code,
                CanonicalJson::encode($eligible->candidateDocument),
                $eligible->proof->canonicalBytes(),
                $eligible->proofHash->value,
                $eligible->candidateManifestHash->value,
                $eligible->candidate->definitionHash->value,
                $eligible->officialManifestHash->value,
                $proof['binding_sha256'],
                $proof['conformance_evidence_sha256'],
                $proof['versions']['contract'],
                $proof['versions']['source_schema'],
                $proof['versions']['formula'],
                $proof['versions']['renderer'],
                $eligible->release->gitSha,
                $artifactBytes,
                hash('sha256', $artifactBytes),
                $artifact['issuer'],
                $artifact['key_id'],
                $eligible->release->approverIdentity,
                $eligible->release->createdAtUtc(),
            ]);
        });
    }

    private function tableCount(string $qualifiedTable): int
    {
        $row = DB::connection()->selectOne("SELECT count(*) AS aggregate FROM {$qualifiedTable}");

        return (int) ($row->aggregate ?? 0);
    }

    private function connectionTableCount(ConnectionInterface $connection, string $qualifiedTable): int
    {
        $row = $connection->selectOne("SELECT count(*) AS aggregate FROM {$qualifiedTable}");

        return (int) ($row->aggregate ?? 0);
    }

    private function principalConnection(
        string $connectionName,
        string $login,
        string $password,
    ): ConnectionInterface {
        $configuration = config('database.connections.pgsql');
        self::assertIsArray($configuration);
        config(["database.connections.{$connectionName}" => array_replace($configuration, [
            'username' => $login,
            'password' => $password,
        ])]);

        return DB::connection($connectionName);
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

    private function logicException(callable $operation): LogicException
    {
        try {
            $operation();
        } catch (LogicException $exception) {
            return $exception;
        }

        self::fail('Expected a publication domain error.');
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
