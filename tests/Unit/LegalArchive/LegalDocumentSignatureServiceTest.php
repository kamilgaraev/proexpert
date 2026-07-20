<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ElectronicSignatureUnavailable;
use App\Services\LegalArchive\Signatures\SignatureContext;
use App\Services\LegalArchive\Signatures\SignerIdentity;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class LegalDocumentSignatureServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'app.fallback_locale' ? 'ru' : $default;
            }
        });
        $container->instance('translator', new class
        {
            public function get(string $key): string
            {
                return $key === 'legal_archive.signatures.provider_unavailable'
                    ? 'Электронное подписание сейчас не настроено.'
                    : $key;
            }
        });
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_disabled_provider_fails_closed_with_human_503(): void
    {
        $provider = new DisabledElectronicSignatureProvider;
        try {
            $provider->start(new SignatureContext(
                1,
                2,
                3,
                str_repeat('a', 64),
                str_repeat('b', 64),
                'https://example.test/callback',
                new SignerIdentitySet([new SignerIdentity('manual', 'Иван')]),
                'operation-id',
                str_repeat('c', 64),
            ));
            self::fail('Disabled provider started a session.');
        } catch (ElectronicSignatureUnavailable $exception) {
            self::assertSame(503, $exception->statusCode());
            self::assertStringContainsString('Электронное подписание', $exception->getMessage());
        }
    }

    public function test_schema_is_phased_forward_only_and_has_database_immutability_guards(): void
    {
        $root = __DIR__.'/../../../';
        $create = file_get_contents($root.'database/migrations/2026_07_19_000600_create_legal_document_signatures.php');
        $indexes = file_get_contents($root.'database/migrations/2026_07_19_000610_create_legal_document_signature_indexes.php');
        $constraints = file_get_contents($root.'database/migrations/2026_07_19_000620_add_legal_document_signature_constraints.php');
        $validate = file_get_contents($root.'database/migrations/2026_07_19_000630_validate_legal_document_signature_constraints.php');
        self::assertIsString($create);
        self::assertIsString($indexes);
        self::assertIsString($constraints);
        self::assertIsString($validate);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexes);
        self::assertStringContainsString('pg_get_indexdef', $indexes);
        self::assertStringContainsString('legal_signature_append_only_guard', $constraints);
        self::assertStringContainsString('legal_archive_version_signature_transition_forbidden', $constraints);
        self::assertStringContainsString("OLD.status = 'uploaded' AND OLD.processing_status = 'ready' AND OLD.is_current", $constraints);
        self::assertStringNotContainsString('DROP TRIGGER', $constraints);
        self::assertStringNotContainsString('%ROWTYPE', $constraints);
        self::assertStringNotContainsString('search_path = pg_catalog, public', $constraints);
        self::assertStringContainsString('CREATE OR REPLACE TRIGGER', $constraints);
        self::assertStringContainsString('$descriptorMigration->up()', $validate);
        $schedule = file_get_contents($root.'routes/console.php');
        self::assertIsString($schedule);
        self::assertStringContainsString('legal-signatures:expire --limit=200', $schedule);
        self::assertStringContainsString('legal-signatures:cleanup-storage --limit=200', $schedule);
        self::assertFileDoesNotExist($root.'app/Jobs/LegalArchive/ExpireLegalSignatureRequests.php');
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validate);
        self::assertStringContainsString('forward_only', $create);
    }

    public function test_provider_callback_contract_contains_correlation_replay_and_authenticity(): void
    {
        $root = __DIR__.'/../../../app/Services/LegalArchive/Signatures/';
        $callback = file_get_contents($root.'SignatureCallback.php');
        $result = file_get_contents($root.'SignatureVerificationResult.php');
        $service = file_get_contents($root.'LegalDocumentSignatureService.php');
        self::assertIsString($callback);
        self::assertIsString($result);
        self::assertIsString($service);
        self::assertStringContainsString('public string $correlationId', $callback);
        self::assertStringContainsString('public string $replayToken', $callback);
        self::assertStringContainsString('public bool $callbackAuthentic', $result);
        self::assertStringContainsString('callback_replay_hash', $service);
        self::assertStringContainsString('signedContentHash', $service);
    }

    public function test_postgres_contract_is_opt_in_and_uses_process_level_race(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalSignaturePostgresConcurrencyTest.php');
        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_SIGNATURE_CONCURRENCY') !== '1'", $source);
        self::assertStringContainsString("getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'", $source);
        self::assertStringContainsString('pcntl_fork', $source);
        self::assertStringContainsString("preg_match('/(?:_test|_testing)$/D'", $source);
        self::assertStringContainsString('legal_signature_index_descriptor_mismatch', $source);
    }
}
