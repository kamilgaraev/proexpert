<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditPhaseBInvariantService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterCredential;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterReadinessService;
use DomainException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditWriterReadinessTest extends TestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $database = new Capsule;
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->connection = $database->getConnection();
        $this->connection->getSchemaBuilder()->create('immutable_audit_rollout', function (Blueprint $table): void {
            $table->boolean('singleton')->primary();
            $table->string('phase');
            $table->unsignedInteger('writer_version');
            $table->string('writer_credential_hash', 64)->nullable();
        });
    }

    public function test_credential_rejects_missing_or_weak_secret_and_never_reuses_raw_secret(): void
    {
        $credential = new ImmutableAuditWriterCredential;
        foreach (['', str_repeat('a', 64), 'short-random-secret'] as $weak) {
            try {
                $credential->derive($weak);
                self::fail('Weak writer secret must be rejected.');
            } catch (DomainException $error) {
                self::assertSame('immutable_audit_writer_secret_not_configured', $error->getMessage());
            }
        }

        $secret = 'v2-test-secret-4f6b9c20-8d31-47ae-b571-53d8412a';
        $derived = $credential->derive($secret);
        self::assertSame(64, strlen($derived));
        self::assertNotSame($secret, $derived);
        self::assertStringNotContainsString($secret, $derived);
        $config = file_get_contents(dirname(__DIR__, 3).'/config/legal_archive.php');
        self::assertIsString($config);
        self::assertStringContainsString("env('LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', '')", $config);
        self::assertStringNotContainsString("env('APP_KEY'", $config);
    }

    public function test_phase_a_and_wrong_fingerprint_are_not_ready_but_phase_b_is_ready(): void
    {
        $secret = 'v2-test-secret-4f6b9c20-8d31-47ae-b571-53d8412a';
        $credential = new ImmutableAuditWriterCredential;
        $readiness = new ImmutableAuditWriterReadinessService($credential, $this->readyInvariants());
        $this->connection->table('immutable_audit_rollout')->insert([
            'singleton' => true,
            'phase' => 'phase_a',
            'writer_version' => 1,
            'writer_credential_hash' => $credential->fingerprint($secret),
        ]);

        self::assertSame('phase_a', $readiness->status($this->connection, $secret)['reason']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable_audit_writer_not_ready');
        $readiness->assertReady($this->connection, $secret);
    }

    public function test_readiness_requires_phase_b_version_and_exact_fingerprint(): void
    {
        $secret = 'v2-test-secret-4f6b9c20-8d31-47ae-b571-53d8412a';
        $credential = new ImmutableAuditWriterCredential;
        $readiness = new ImmutableAuditWriterReadinessService($credential, $this->readyInvariants());
        $this->connection->table('immutable_audit_rollout')->insert([
            'singleton' => true,
            'phase' => 'phase_b',
            'writer_version' => 2,
            'writer_credential_hash' => $credential->fingerprint($secret),
        ]);

        self::assertTrue($readiness->status($this->connection, $secret)['ready']);
        self::assertSame($credential->derive($secret), $readiness->assertReady($this->connection, $secret));
        self::assertSame('writer_credential_mismatch', $readiness->status(
            $this->connection,
            'other-test-secret-3104e955-c814-4e63-9ea8-5d303efe',
        )['reason']);
    }

    public function test_phase_b_schema_drift_fails_readiness_without_cache(): void
    {
        $secret = 'v2-test-secret-4f6b9c20-8d31-47ae-b571-53d8412a';
        $credential = new ImmutableAuditWriterCredential;
        $snapshots = [
            [
                'sequence_exists' => true,
                'allocator_valid' => true,
                'guard_trigger_valid' => true,
                'aggregate_index_valid' => true,
                'legacy_index_valid' => true,
            ],
            [
                'sequence_exists' => true,
                'allocator_valid' => true,
                'guard_trigger_valid' => false,
                'aggregate_index_valid' => true,
                'legacy_index_valid' => true,
            ],
        ];
        $readiness = new ImmutableAuditWriterReadinessService(
            $credential,
            new ImmutableAuditPhaseBInvariantService(static function () use (&$snapshots): array {
                return array_shift($snapshots);
            }),
        );
        $this->connection->table('immutable_audit_rollout')->insert([
            'singleton' => true,
            'phase' => 'phase_b',
            'writer_version' => 2,
            'writer_credential_hash' => $credential->fingerprint($secret),
        ]);

        self::assertTrue($readiness->status($this->connection, $secret)['ready']);
        self::assertSame('immutable_audit_writer_guard_invalid', $readiness->status($this->connection, $secret)['reason']);
    }

    private function readyInvariants(): ImmutableAuditPhaseBInvariantService
    {
        return new ImmutableAuditPhaseBInvariantService(static fn (): array => [
            'sequence_exists' => true,
            'allocator_valid' => true,
            'guard_trigger_valid' => true,
            'aggregate_index_valid' => true,
            'legacy_index_valid' => true,
        ]);
    }
}
