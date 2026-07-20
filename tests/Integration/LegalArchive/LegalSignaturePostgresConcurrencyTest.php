<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ElectronicSignatureEvidence;
use App\Services\LegalArchive\Signatures\ElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ExternalOriginalData;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\LegalSignatureArtifactReconciler;
use App\Services\LegalArchive\Signatures\LegalSignatureCleanupDebtService;
use App\Services\LegalArchive\Signatures\LegalSignatureCleanupMetrics;
use App\Services\LegalArchive\Signatures\LegalSignatureExpiryService;
use App\Services\LegalArchive\Signatures\LegalSignatureProjection;
use App\Services\LegalArchive\Signatures\SignatureArtifact;
use App\Services\LegalArchive\Signatures\SignatureCallback;
use App\Services\LegalArchive\Signatures\SignatureContext;
use App\Services\LegalArchive\Signatures\SignatureSession;
use App\Services\LegalArchive\Signatures\SignatureVerificationContext;
use App\Services\LegalArchive\Signatures\SignatureVerificationResult;
use App\Services\LegalArchive\Signatures\SignerIdentity;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use App\Services\Storage\FileService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class LegalSignaturePostgresConcurrencyTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (getenv('LEGAL_ARCHIVE_PG_SIGNATURE_CONCURRENCY') !== '1'
            || ! is_string($dsn) || $dsn === '' || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('Dedicated PostgreSQL signature concurrency contract database is not enabled.');
        }
        $this->database = new Capsule;
        $this->database->addConnection($this->connectionConfig($dsn), 'signature_first');
        $this->database->addConnection($this->connectionConfig($dsn), 'signature_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('signature_first');
        $this->first = $this->database->getConnection('signature_first');
        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_signature_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        foreach (['signature_first', 'signature_second'] as $connection) {
            $this->database->getConnection($connection)->statement("SET search_path TO {$this->schema}");
        }
        $this->installBaseSchema();
        (require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000280_allow_fenced_legal_document_version_rescan.php')->up();
        foreach (['000600_create_legal_document_signatures', '000610_create_legal_document_signature_indexes', '000620_add_legal_document_signature_constraints', '000630_validate_legal_document_signature_constraints'] as $suffix) {
            (require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php")->up();
        }
        $this->seedAggregate();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_signature_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_parallel_same_command_creates_only_one_signature_request(): void
    {
        $gate = 'signature-race-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($worker === 0 ? 'signature_first' : 'signature_second');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    $document = (new LegalArchiveDocument)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                    $version = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                    $actor = (new User)->setConnection($connection->getName())->forceFill([
                        'id' => 1, 'current_organization_id' => 1,
                    ]);
                    $actor->exists = true;
                    $this->signatureService($connection)->createRequest(
                        $document, $version, $actor, 'paper',
                        new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]),
                        'same-command',
                    );
                    $exit = 0;
                } catch (\Throwable $error) {
                    $exit = 20;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_race_fork_failed');
            }
            $children[] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[] = pcntl_wexitstatus($status);
        }
        sort($outcomes);
        self::assertSame([0, 0], $outcomes);
        self::assertSame(1, $this->first->table('legal_signature_requests')->count());
        self::assertSame('frozen', $this->first->table('legal_archive_document_versions')->where('id', 1)->value('status'));
    }

    public function test_index_descriptor_drift_and_raw_evidence_mutation_fail_closed(): void
    {
        $this->first->statement('DROP INDEX legal_signature_requests_pending_idx');
        $this->first->statement("CREATE INDEX legal_signature_requests_pending_idx ON legal_signature_requests (organization_id, expires_at) WHERE status = 'pending'");
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000610_create_legal_document_signature_indexes.php';
        try {
            $migration->up();
            self::fail('A valid index with a wrong descriptor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('legal_signature_index_descriptor_mismatch:legal_signature_requests_pending_idx', $exception->getMessage());
        }

        $requestId = $this->first->table('legal_signature_requests')->insertGetId($this->requestRow());
        $signatureId = $this->first->table('legal_document_signatures')->insertGetId([
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1, 'signature_request_id' => $requestId,
            'party_id' => null, 'method' => 'paper', 'provider' => null, 'signer_name' => 'Иван',
            'signers' => json_encode([['name' => 'Иван']], JSON_THROW_ON_ERROR), 'signed_content_hash' => str_repeat('a', 64),
            'signature_path' => null, 'signature_content_hash' => null, 'storage_version_id' => null, 'storage_etag' => null,
            'detected_mime_type' => null, 'certificate_metadata' => '{}', 'provider_metadata' => '{}',
            'storage_location' => 'Архив', 'signed_at' => now()->subDay(), 'verified_at' => null,
            'verification_status' => 'registered', 'signature_kind' => 'paper_original', 'container_format' => null,
            'signer_snapshot_hash' => str_repeat('e', 64), 'signer_user_id' => null, 'signer_organization_id' => null,
            'party_role_snapshot' => null, 'certificate_fingerprint' => null, 'certificate_serial' => null,
            'certificate_issuer' => null, 'certificate_valid_from' => null, 'certificate_valid_until' => null,
            'authority_confirmed' => false, 'time_source' => 'operator', 'diagnostic_code' => 'paper_original_registered',
            'signing_session_id' => null, 'client_ip_hash' => null, 'user_agent_hash' => null,
            'revocation_reason' => null, 'registered_by_user_id' => 1,
            'idempotency_key' => 'paper', 'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->first->table('legal_document_signatures')->where('id', $signatureId)->update(['storage_location' => 'Подмена']);
            self::fail('Raw signature evidence mutation was accepted.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_signature_evidence_immutable', $exception->getMessage());
        }
    }

    public function test_callback_and_expiry_race_has_one_explicit_terminal_winner(): void
    {
        $connection = $this->database->getConnection('signature_first');
        $document = (new LegalArchiveDocument)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $version = (new LegalArchiveDocumentVersion)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('signature_first')->forceFill(['id' => 1, 'current_organization_id' => 1]);
        $actor->exists = true;
        $provider = new PostgresRaceSignatureProvider;
        $service = $this->signatureService($connection, $provider, $this->signatureStorage($connection));
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic',
            new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]),
            'callback-expiry-race', provider: 'race-provider', expiresAt: new \DateTimeImmutable('+1 minute'),
        );
        $session = $service->startElectronicSession($request, $actor);
        $gate = 'callback-expiry-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        foreach (['callback', 'expiry'] as $role) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $childConnection = $manager->connection($role === 'callback' ? 'signature_first' : 'signature_second');
                $childConnection->statement("SET search_path TO {$this->schema}");
                $childConnection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    if ($role === 'callback') {
                        $childRequest = $this->signatureRequest($childConnection, (int) $request->id);
                        $this->signatureService($childConnection, new PostgresRaceSignatureProvider, $this->signatureStorage($childConnection))
                            ->completeElectronic(new SignatureCallback(
                                'race-provider', $session->providerRequestId, $session->correlationId,
                                'callback-expiry-event', ['status' => 'signed'],
                            ));
                        $exit = $childRequest->id > 0 ? 0 : 20;
                    } else {
                        Carbon::setTestNow(now()->addMinutes(2));
                        $expired = (new LegalSignatureExpiryService(
                            $childConnection, $this->audit(), new LegalSignatureProjection($childConnection),
                        ))->expireDue();
                        $exit = $expired === 1 ? 0 : 10;
                    }
                } catch (\DomainException $error) {
                    $exit = $role === 'callback' && in_array($error->getMessage(), [
                        'legal_signature_callback_stale_generation', 'legal_signature_request_not_pending',
                    ], true) ? 11 : 20;
                } catch (\Throwable) {
                    $exit = 20;
                }
                $childConnection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_race_fork_failed');
            }
            $children[$role] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $outcomes = [];
        foreach ($children as $role => $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[$role] = pcntl_wexitstatus($status);
        }
        self::assertContains($outcomes, [
            ['callback' => 0, 'expiry' => 10],
            ['callback' => 11, 'expiry' => 0],
        ]);
        $terminal = $this->first->table('legal_signature_requests')->where('id', $request->id)->value('status');
        self::assertContains($terminal, ['completed', 'expired']);
        self::assertSame($terminal === 'completed' ? 1 : 0, $this->first->table('legal_document_signatures')->count());
    }

    public function test_callback_cannot_commit_after_a_new_provider_generation_starts(): void
    {
        $document = (new LegalArchiveDocument)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $version = (new LegalArchiveDocumentVersion)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('signature_first')->forceFill(['id' => 1, 'current_organization_id' => 1]);
        $actor->exists = true;
        $provider = new PostgresRaceSignatureProvider(onComplete: function (): void {
            $first = $this->first->table('legal_signature_provider_operations')->sole();
            $this->first->table('legal_signature_provider_operations')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $first->organization_id,
                'document_id' => $first->document_id,
                'document_version_id' => $first->document_version_id,
                'signature_request_id' => $first->signature_request_id,
                'provider' => $first->provider,
                'status' => 'started',
                'correlation_id' => $first->correlation_id,
                'provider_idempotency_key' => hash('sha256', 'provider-generation-2'),
                'request_idempotency_key' => $first->request_idempotency_key,
                'generation' => 2,
                'supersedes_operation_id' => $first->id,
                'attempt_count' => 1,
                'provider_request_id' => 'race-provider-request-2',
                'redirect_url' => 'https://race-provider.test/session-2',
                'session_expires_at' => now()->addMinutes(5),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $service = $this->signatureService($this->first, $provider, $this->signatureStorage($this->first));
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic',
            new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]),
            'provider-generation-fence', provider: 'race-provider',
        );
        $session = $service->startElectronicSession($request, $actor);

        try {
            $service->completeElectronic(new SignatureCallback(
                'race-provider', $session->providerRequestId, $session->correlationId,
                'provider-generation-fence-event', ['status' => 'signed'],
            ));
            self::fail('A callback from the superseded provider generation was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('legal_signature_callback_stale_generation', $exception->getMessage());
        }
        self::assertSame(0, $this->first->table('legal_document_signatures')->count());
        self::assertSame(2, $this->first->table('legal_signature_provider_operations')->count());
    }

    public function test_parallel_s3_compensation_never_deletes_the_winning_reference(): void
    {
        $document = (new LegalArchiveDocument)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $version = (new LegalArchiveDocumentVersion)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('signature_first')->forceFill(['id' => 1, 'current_organization_id' => 1]);
        $actor->exists = true;
        $request = $this->signatureService($this->first)->createRequest(
            $document, $version, $actor, 'external_electronic',
            new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]),
            's3-reference-race', provider: 'race-provider',
        );
        $gate = 's3-reference-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        foreach (['winner', 'loser'] as $role) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($role === 'winner' ? 'signature_first' : 'signature_second');
                $connection->statement("SET search_path TO {$this->schema}");
                $signers = new SignerIdentitySet([new SignerIdentity('manual', $role === 'winner' ? 'Signer' : 'Other')]);
                try {
                    $this->signatureService($connection, new PostgresRaceSignatureProvider, $this->signatureStorage($connection, $gate))
                        ->registerExternalOriginal(
                            $this->signatureRequest($connection, (int) $request->id),
                            UploadedFile::fake()->createWithContent(
                                'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
                            ),
                            (new User)->setConnection($connection->getName())->forceFill(['id' => 1, 'current_organization_id' => 1]),
                            new ExternalOriginalData('race-provider', $this->externalEvidence($signers), "s3-{$role}"),
                        );
                    $exit = $role === 'winner' ? 0 : 20;
                } catch (\DomainException $error) {
                    $exit = $role === 'loser' && in_array($error->getMessage(), [
                        'legal_signature_signers_mismatch', 'legal_signature_request_not_pending',
                    ], true) ? 11 : 20;
                } catch (\Throwable) {
                    $exit = 20;
                }
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_race_fork_failed');
            }
            $children[$role] = $pid;
        }
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($this->first->table('signature_test_put_waiters')->where('gate_key', $gate)->count() === 2) {
                break;
            }
            usleep(50_000);
        }
        self::assertSame(2, $this->first->table('signature_test_put_waiters')->where('gate_key', $gate)->count());
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $outcomes = [];
        foreach ($children as $role => $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[$role] = pcntl_wexitstatus($status);
        }
        self::assertSame(['winner' => 0, 'loser' => 11], $outcomes);
        self::assertSame(1, $this->first->table('legal_document_signatures')->count());
        self::assertSame(0, $this->first->table('signature_test_storage_deletions')->count());
        $artifact = $this->first->table('legal_signature_artifacts')->sole();
        self::assertSame('referenced', $artifact->state);
        self::assertSame(0, (int) $artifact->claim_count);
    }

    public function test_parallel_reconcilers_recover_one_stale_artifact_idempotently(): void
    {
        $requestId = $this->first->table('legal_signature_requests')->insertGetId($this->requestRow());
        $body = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
        $path = 'org-1/reconcile-race.p7s';
        $versionId = 'reconcile-race-version';
        $this->first->table('signature_test_storage_objects')->insert([
            'storage_path' => $path, 'storage_version_id' => $versionId, 'content_hash' => hash('sha256', $body),
        ]);
        $this->first->table('legal_signature_artifacts')->insert([
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1,
            'signature_request_id' => $requestId, 'artifact_key' => str_repeat('8', 64),
            'storage_path' => $path, 'storage_version_id' => $versionId,
            'content_hash' => hash('sha256', $body), 'state' => 'uploaded', 'claim_count' => 2,
            'cleanup_owned' => true, 'upload_lease_token_hash' => str_repeat('9', 64),
            'upload_lease_expires_at' => now()->subMinute(), 'attempt_count' => 1,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);
        $gate = 'artifact-reconcile-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        foreach (['signature_first', 'signature_second'] as $connectionName) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($connectionName);
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    (new LegalSignatureArtifactReconciler(
                        $this->signatureStorage($connection), $connection, $this->audit(), $this->metrics(),
                    ))->reconcile();
                    $exit = 0;
                } catch (\Throwable) {
                    $exit = 20;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_reconcile_fork_failed');
            }
            $children[] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $artifact = $this->first->table('legal_signature_artifacts')->sole();
        self::assertSame('deleting', $artifact->state);
        self::assertSame(0, (int) $artifact->claim_count);
        self::assertSame(1, $this->first->table('legal_archive_file_cleanup_debts')->count());
    }

    public function test_slow_uploader_is_fenced_after_reconciler_claims_expired_attempt(): void
    {
        $document = (new LegalArchiveDocument)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $version = (new LegalArchiveDocumentVersion)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('signature_first')->forceFill(['id' => 1, 'current_organization_id' => 1]);
        $actor->exists = true;
        $signers = new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]);
        $request = $this->signatureService($this->first)->createRequest(
            $document, $version, $actor, 'external_electronic', $signers,
            'slow-uploader-fence', provider: 'race-provider',
        );
        $gate = 'slow-uploader-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $pid = pcntl_fork();
        if ($pid === 0) {
            $manager = $this->database->getDatabaseManager();
            $manager->disconnect('signature_first');
            $manager->disconnect('signature_second');
            $connection = $manager->connection('signature_second');
            $connection->statement("SET search_path TO {$this->schema}");
            $childActor = (new User)->setConnection('signature_second')->forceFill(['id' => 1, 'current_organization_id' => 1]);
            $childActor->exists = true;
            try {
                $this->signatureService($connection, new PostgresRaceSignatureProvider, $this->slowUploadStorage($connection, $gate))
                    ->registerExternalOriginal(
                        $this->signatureRequest($connection, (int) $request->id),
                        UploadedFile::fake()->createWithContent(
                            'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
                        ),
                        $childActor,
                        new ExternalOriginalData('race-provider', $this->externalEvidence($signers), 'slow-uploader-import'),
                    );
                $exit = 20;
            } catch (\DomainException $error) {
                $exit = $error->getMessage() === 'legal_signature_artifact_attempt_stale' ? 0 : 20;
            } catch (\Throwable) {
                $exit = 20;
            }
            exit($exit);
        }
        if ($pid < 0) {
            throw new \RuntimeException('legal_signature_slow_uploader_fork_failed');
        }
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($this->first->table('signature_test_put_waiters')->where('gate_key', $gate)->exists()) {
                break;
            }
            usleep(50_000);
        }
        self::assertTrue($this->first->table('signature_test_put_waiters')->where('gate_key', $gate)->exists());
        $this->first->table('legal_signature_artifacts')->update(['upload_lease_expires_at' => now()->subSecond()]);
        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $this->signatureStorage($this->first), $this->first, $this->audit(), $this->metrics(),
        ))->reconcile());
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame(0, $this->first->table('legal_document_signatures')->count());
        self::assertSame('deleting', $this->first->table('legal_signature_artifacts')->value('state'));
        self::assertSame(1, $this->first->table('legal_archive_file_cleanup_debts')->count());
    }

    public function test_reconciler_and_cleanup_worker_share_lock_order_without_deadlock(): void
    {
        $requestId = $this->first->table('legal_signature_requests')->insertGetId($this->requestRow());
        $path = 'org-1/cleanup-reconcile-race.p7s';
        $versionId = 'cleanup-reconcile-version';
        $this->first->table('legal_signature_artifacts')->insert([
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1,
            'signature_request_id' => $requestId, 'artifact_key' => str_repeat('7', 64),
            'storage_path' => $path, 'storage_version_id' => $versionId, 'content_hash' => str_repeat('a', 64),
            'state' => 'deleting', 'claim_count' => 0, 'cleanup_owned' => true,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);
        $this->first->table('legal_archive_file_cleanup_debts')->insert([
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1,
            'storage_path' => $path, 'storage_version_id' => $versionId,
            'debt_key' => \App\Services\LegalArchive\Files\LegalCleanupDebtKey::for(1, $path, $versionId),
            'content_hash' => str_repeat('a', 64), 'reason' => 'signature_registration_failed',
            'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gate = 'cleanup-reconcile-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        foreach (['cleanup', 'reconcile'] as $role) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($role === 'cleanup' ? 'signature_first' : 'signature_second');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                try {
                    if ($role === 'cleanup') {
                        (new LegalSignatureCleanupDebtService(
                            $this->signatureStorage($connection), $connection, $this->audit(), $this->metrics(),
                        ))->processDue();
                    } else {
                        (new LegalSignatureArtifactReconciler(
                            $this->signatureStorage($connection), $connection, $this->audit(), $this->metrics(),
                        ))->reconcile();
                    }
                    $exit = 0;
                } catch (\Throwable) {
                    $exit = 20;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            $children[] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame('deleted', $this->first->table('legal_signature_artifacts')->value('state'));
        self::assertNotNull($this->first->table('legal_archive_file_cleanup_debts')->value('resolved_at'));
        self::assertSame(1, $this->first->table('signature_test_storage_deletions')->count());
    }

    public function test_failed_verification_and_replacement_race_has_explicit_loser_then_corrects_signature(): void
    {
        $document = (new LegalArchiveDocument)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $version = (new LegalArchiveDocumentVersion)->setConnection('signature_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('signature_first')->forceFill(['id' => 1, 'current_organization_id' => 1]);
        $actor->exists = true;
        $signers = new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]);
        $service = $this->signatureService($this->first, new PostgresRaceSignatureProvider, $this->signatureStorage($this->first));
        $request = $service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers,
            'verification-race-initial', provider: 'race-provider',
        );
        $signature = $service->registerExternalOriginal(
            $request,
            UploadedFile::fake()->createWithContent(
                'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
            ),
            $actor,
            new ExternalOriginalData('race-provider', $this->externalEvidence($signers), 'verification-race-import'),
        );
        $gate = 'verify-replace-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gate]);
        $children = [];
        foreach (['verify', 'replace'] as $role) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('signature_first');
                $manager->disconnect('signature_second');
                $connection = $manager->connection($role === 'verify' ? 'signature_first' : 'signature_second');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
                $childActor = (new User)->setConnection($connection->getName())->forceFill(['id' => 1, 'current_organization_id' => 1]);
                $childActor->exists = true;
                try {
                    if ($role === 'verify') {
                        $childSignature = (new \App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature)
                            ->setConnection($connection->getName())->newQuery()->findOrFail($signature->id);
                        $this->signatureService($connection, new PostgresRaceSignatureProvider('failed'), $this->signatureStorage($connection))
                            ->verify($childSignature, $childActor, 'verification-race-failed');
                    } else {
                        $childDocument = (new LegalArchiveDocument)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                        $childVersion = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                        $this->signatureService($connection, new PostgresRaceSignatureProvider, $this->signatureStorage($connection))
                            ->createRequest(
                                $childDocument, $childVersion, $childActor, 'external_electronic', $signers,
                                'verification-race-replacement', provider: 'race-provider', replacesRequestId: (int) $request->id,
                            );
                    }
                    $exit = 0;
                } catch (\DomainException $error) {
                    $exit = $role === 'replace' && $error->getMessage() === 'legal_signature_replacement_invalid' ? 11 : 20;
                } catch (\Throwable) {
                    $exit = 20;
                }
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);
                exit($exit);
            }
            if ($pid < 0) {
                throw new \RuntimeException('legal_signature_race_fork_failed');
            }
            $children[$role] = $pid;
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gate]);
        $outcomes = [];
        foreach ($children as $role => $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[$role] = pcntl_wexitstatus($status);
        }
        self::assertSame(0, $outcomes['verify']);
        self::assertContains($outcomes['replace'], [0, 11]);
        $replacement = $this->first->table('legal_signature_requests')->where('replaces_request_id', $request->id)->first();
        if ($replacement === null) {
            $replacementModel = $service->createRequest(
                $document->refresh(), $version->refresh(), $actor, 'external_electronic', $signers,
                'verification-race-replacement', provider: 'race-provider', replacesRequestId: (int) $request->id,
            );
        } else {
            $replacementModel = $this->signatureRequest($this->first, (int) $replacement->id);
        }
        $corrected = $service->registerExternalOriginal(
            $replacementModel,
            UploadedFile::fake()->createWithContent(
                'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
            ),
            $actor,
            new ExternalOriginalData('race-provider', $this->externalEvidence($signers), 'verification-race-corrected'),
        );
        $verification = $service->verify($corrected, $actor, 'verification-race-corrected-result');
        self::assertSame('verified', $verification->status);
        self::assertSame('signed', $document->refresh()->signature_status);
        self::assertSame('signed', $version->refresh()->status);
    }

    private function installBaseSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigint PRIMARY KEY);
CREATE TABLE users (id bigint PRIMARY KEY);
CREATE TABLE legal_archive_documents (
 id bigint PRIMARY KEY, organization_id bigint NOT NULL, title text NOT NULL DEFAULT 'Document', current_primary_version_id bigint,
 approval_status text, lifecycle_status text, signature_status text, legal_significance_status text, lock_version bigint NOT NULL DEFAULT 0,
 type_profile_code text, structured_fields jsonb, archived_at timestamptz, deleted_at timestamptz, created_at timestamptz, updated_at timestamptz,
 UNIQUE (id, organization_id)
);
CREATE TABLE legal_archive_document_files (id bigint PRIMARY KEY, document_id bigint NOT NULL, organization_id bigint NOT NULL, current_version_id bigint, UNIQUE (id, document_id, organization_id));
CREATE TABLE legal_archive_document_versions (
 id bigint PRIMARY KEY, document_id bigint NOT NULL, document_file_id bigint NOT NULL, organization_id bigint NOT NULL,
 version_number text NOT NULL, version_label text, is_current boolean NOT NULL, status text NOT NULL,
 processing_status text NOT NULL, file_path text NOT NULL, original_filename text NOT NULL, mime_type text,
 size_bytes bigint NOT NULL, content_hash text, metadata_hash text, uploaded_by_user_id bigint, uploaded_at timestamptz,
 metadata jsonb, created_at timestamptz, updated_at timestamptz, UNIQUE (id, document_id, organization_id)
);
CREATE TABLE legal_document_parties (
 id bigint PRIMARY KEY, snapshot_set_id bigint NOT NULL, document_version_id bigint NOT NULL,
 document_id bigint NOT NULL, organization_id bigint NOT NULL
);
CREATE TABLE legal_archive_document_type_profiles (id uuid PRIMARY KEY);
CREATE TABLE legal_archive_file_cleanup_debts (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, storage_path text NOT NULL, reason text NOT NULL,
 attempts integer NOT NULL DEFAULT 0, next_attempt_at timestamptz, last_error text, resolved_at timestamptz,
 created_at timestamptz, updated_at timestamptz, UNIQUE (organization_id, storage_path)
);
CREATE TABLE legal_document_access_grants (
 id bigserial PRIMARY KEY, abilities jsonb NOT NULL, subject_kind text NOT NULL
);
CREATE TABLE signature_test_storage_objects (
 storage_path text NOT NULL, storage_version_id text NOT NULL, content_hash text NOT NULL,
 PRIMARY KEY (storage_path, storage_version_id)
);
CREATE TABLE signature_test_storage_deletions (storage_path text NOT NULL, storage_version_id text);
CREATE TABLE signature_test_put_waiters (id bigserial PRIMARY KEY, gate_key text NOT NULL);
ALTER TABLE legal_document_access_grants ADD CONSTRAINT legal_document_access_abilities_check
CHECK (jsonb_typeof(abilities) = 'array' AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view","comment","approve","sign","download","manage"]'::jsonb AND (NOT abilities ? 'manage' OR subject_kind = 'internal_user'));
SQL);
    }

    private function seedAggregate(): void
    {
        $this->first->table('organizations')->insert(['id' => 1]);
        $this->first->table('users')->insert(['id' => 1]);
        $this->first->table('legal_archive_documents')->insert(['id' => 1, 'organization_id' => 1]);
        $this->first->table('legal_archive_document_files')->insert(['id' => 1, 'document_id' => 1, 'organization_id' => 1]);
        $this->first->table('legal_archive_document_versions')->insert([
            'id' => 1, 'document_id' => 1, 'document_file_id' => 1, 'organization_id' => 1,
            'version_number' => '1', 'is_current' => true, 'status' => 'frozen', 'processing_status' => 'ready',
            'file_path' => 'org-1/document.pdf', 'original_filename' => 'document.pdf', 'size_bytes' => 1,
            'content_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->first->table('legal_archive_documents')->where('id', 1)->update([
            'current_primary_version_id' => 1, 'approval_status' => 'approved', 'lifecycle_status' => 'approved',
            'signature_status' => 'not_signed', 'legal_significance_status' => 'not_confirmed', 'lock_version' => 0,
            'type_profile_code' => 'contract.work', 'structured_fields' => '{}',
        ]);
    }

    private function requestRow(): array
    {
        return [
            'organization_id' => 1, 'document_id' => 1, 'document_version_id' => 1, 'party_id' => null,
            'method' => 'paper', 'provider' => null, 'status' => 'pending', 'signed_content_hash' => str_repeat('a', 64),
            'signers' => json_encode([['name' => 'Иван']], JSON_THROW_ON_ERROR), 'signer_snapshot_hash' => str_repeat('e', 64),
            'profile_code' => 'contract.work', 'profile_lock_version' => 0,
            'allowed_signature_kinds' => json_encode(['paper_original'], JSON_THROW_ON_ERROR),
            'required_signature_kinds' => json_encode([], JSON_THROW_ON_ERROR),
            'allowed_signature_formats' => json_encode(['detached_cades'], JSON_THROW_ON_ERROR),
            'requirement_snapshot_hash' => str_repeat('f', 64), 'requirement_group_key' => str_repeat('1', 64),
            'replaces_request_id' => null, 'correlation_id' => str_repeat('c', 64),
            'idempotency_key' => 'same-command', 'request_hash' => str_repeat('d', 64), 'requested_by_user_id' => 1,
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function signatureService(
        ConnectionInterface $connection,
        ?ElectronicSignatureProvider $provider = null,
        ?FileService $storage = null,
    ): LegalDocumentSignatureService {
        $authorizer = new class implements LegalDocumentAuthorizer
        {
            public function authorize(User $user, LegalArchiveDocument $document, string $ability): void {}

            public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void {}
        };

        return new LegalDocumentSignatureService(
            $provider ?? new DisabledElectronicSignatureProvider,
            $authorizer,
            $this->audit(),
            $storage ?? $this->createMock(FileService::class),
            $connection,
        );
    }

    private function audit(): LegalDocumentAudit
    {
        return new class implements LegalDocumentAudit
        {
            public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

            public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

            public function recordContractForActorId(
                string $event,
                \App\Models\Contract $contract,
                ?int $actorId,
                array $context = [],
            ): void {}
        };
    }

    private function metrics(): LegalSignatureCleanupMetrics
    {
        return new class implements LegalSignatureCleanupMetrics
        {
            public function increment(string $metric, array $labels = []): void {}
        };
    }

    private function signatureStorage(ConnectionInterface $connection, ?string $putGate = null): FileService
    {
        $storage = $this->createMock(FileService::class);
        $storage->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use ($connection, $putGate): array {
            if ($putGate !== null) {
                $connection->table('signature_test_put_waiters')->insert(['gate_key' => $putGate]);
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$putGate]);
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$putGate]);
            }
            $versionId = 'race-version-'.hash('sha256', $path.$body);
            $created = $connection->table('signature_test_storage_objects')->insertOrIgnore([
                'storage_path' => $path, 'storage_version_id' => $versionId, 'content_hash' => hash('sha256', $body),
            ]) === 1;

            return [
                'path' => $path, 'body' => $body, 'size' => strlen($body), 'sha256' => hash('sha256', $body),
                'etag' => 'race-etag', 'version_id' => $versionId, 'content_type' => $contentType, 'created' => $created,
            ];
        });
        $storage->method('removeImmutable')->willReturnCallback(static function (string $path, ?string $versionId) use ($connection): void {
            $connection->table('signature_test_storage_deletions')->insert([
                'storage_path' => $path, 'storage_version_id' => $versionId,
            ]);
        });
        $storage->method('describeVersion')->willReturnCallback(static function (string $path, ?string $versionId) use ($connection): array {
            $body = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
            $object = $connection->table('signature_test_storage_objects')->where('storage_path', $path)
                ->when($versionId !== null, static fn ($query) => $query->where('storage_version_id', $versionId))->first();
            if ($object === null) {
                throw new \RuntimeException('s3_pinned_object_unavailable');
            }

            return [
                'path' => $path, 'body' => $body, 'size' => strlen($body),
                'sha256' => (string) $object->content_hash,
                'version_id' => (string) $object->storage_version_id, 'etag' => 'race-etag',
                'content_type' => 'application/pkcs7-signature',
            ];
        });

        return $storage;
    }

    private function slowUploadStorage(ConnectionInterface $connection, string $gate): FileService
    {
        $storage = $this->createMock(FileService::class);
        $storage->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use ($connection, $gate): array {
            $versionId = 'slow-version-'.hash('sha256', $path.$body);
            $connection->table('signature_test_storage_objects')->insertOrIgnore([
                'storage_path' => $path, 'storage_version_id' => $versionId, 'content_hash' => hash('sha256', $body),
            ]);
            $connection->table('signature_test_put_waiters')->insert(['gate_key' => $gate]);
            $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gate]);
            $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gate]);

            return [
                'path' => $path, 'body' => $body, 'size' => strlen($body), 'sha256' => hash('sha256', $body),
                'etag' => 'slow-etag', 'version_id' => $versionId, 'content_type' => $contentType, 'created' => true,
            ];
        });

        return $storage;
    }

    private function signatureRequest(ConnectionInterface $connection, int $id): \App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest
    {
        return (new \App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest)
            ->setConnection($connection->getName())->newQuery()->findOrFail($id);
    }

    private function externalEvidence(SignerIdentitySet $signers): ElectronicSignatureEvidence
    {
        $signedAt = new \DateTimeImmutable('-1 minute');

        return new ElectronicSignatureEvidence(
            'detached_cades', 'p7s', $signers, str_repeat('d', 64), '01AB', 'Race CA',
            $signedAt->modify('-1 year'), $signedAt->modify('+1 year'), true, 'operator', 'verified',
            $signedAt, new \DateTimeImmutable('now'),
        );
    }

    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if (is_string($key) && is_string($value)) {
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '', 'username' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER'),
            'password' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD'), 'charset' => 'utf8', 'prefix' => '',
        ];
    }
}

final class PostgresRaceSignatureProvider implements ElectronicSignatureProvider
{
    public function __construct(
        private readonly string $status = 'verified',
        private readonly ?\Closure $onComplete = null,
    ) {}

    private function signers(): SignerIdentitySet
    {
        return new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]);
    }

    private function evidence(?\DateTimeImmutable $signedAt = null, ?SignerIdentitySet $signers = null): ElectronicSignatureEvidence
    {
        $signedAt ??= new \DateTimeImmutable('-1 minute');
        $signers ??= $this->signers();

        return new ElectronicSignatureEvidence(
            'detached_cades', 'p7s', $signers, str_repeat('d', 64), '01AB', 'Race CA',
            $signedAt->modify('-1 year'), $signedAt->modify('+1 year'), true, 'provider',
            $this->status === 'verified' ? 'verified' : 'verification_failed',
            $signedAt, new \DateTimeImmutable('now'),
        );
    }

    public function start(SignatureContext $context): SignatureSession
    {
        return new SignatureSession(
            'race-provider', 'race-provider-request', $context->correlationId,
            'https://race-provider.test/session', (new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        );
    }

    public function complete(SignatureCallback $callback): SignatureVerificationResult
    {
        ($this->onComplete ?? static fn (): null => null)();
        $artifact = new SignatureArtifact(
            pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
            'signature.p7s', 'application/pkcs7-signature',
        );

        return new SignatureVerificationResult(
            $this->status, 'race-provider', $callback->providerRequestId, $callback->correlationId,
            str_repeat('a', 64), $this->signers(), $this->evidence(), $artifact, true,
        );
    }

    public function verify(SignatureVerificationContext $context): SignatureVerificationResult
    {
        $request = $context->signature->request()->firstOrFail();
        $signers = SignerIdentitySet::fromSnapshot((array) $context->signature->signers);
        $signedAt = new \DateTimeImmutable((string) $context->signature->signed_at);

        return new SignatureVerificationResult(
            $this->status, (string) $context->signature->provider,
            "external-verification:{$context->signature->id}", (string) $request->correlation_id,
            (string) $context->signature->signed_content_hash, $signers, $this->evidence($signedAt, $signers), $context->artifact,
        );
    }
}
