<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LegalSignatureCurrentObjectContractTest extends TestCase
{
    public function test_bind_contract_accepts_the_observed_etag_for_atomic_persistence(): void
    {
        $method = new ReflectionMethod(LegalDocumentSignatureService::class, 'bindSignatureArtifact');

        self::assertSame(
            ['organizationId', 'artifactKey', 'attemptToken', 'created', 'etag'],
            array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters()),
        );
    }

    public function test_bind_recovery_and_reconciliation_persist_etag_on_the_canonical_artifact(): void
    {
        $service = $this->source('app/Services/LegalArchive/Signatures/LegalDocumentSignatureService.php');
        $reconciler = $this->source('app/Services/LegalArchive/Signatures/LegalSignatureArtifactReconciler.php');

        self::assertGreaterThanOrEqual(2, substr_count($service, "'storage_etag' => \$etag"));
        self::assertStringContainsString("'storage_etag' => \$description['etag']", $reconciler);
    }

    public function test_stale_current_recovery_uses_guard_compatible_uploaded_then_deleting_transitions(): void
    {
        $recovery = $this->methodSource('recoverStaleCurrentArtifact');
        $uploaded = strpos($recovery, "'state' => 'uploaded'");
        $deleting = strpos($recovery, "'state' => 'deleting'");

        self::assertNotFalse($uploaded);
        self::assertNotFalse($deleting);
        self::assertTrue($uploaded < $deleting);
        self::assertStringContainsString("'storage_etag' => \$etag", substr($recovery, $uploaded, $deleting - $uploaded));

        $migration = $this->source('database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php');
        self::assertStringContainsString(
            "OLD.state = 'ambiguous' AND NEW.state IN ('ambiguous','uploaded','referenced','confirmed_absent')",
            $migration,
        );
        self::assertStringContainsString(
            "OLD.state = 'confirmed_absent' AND NEW.state IN ('confirmed_absent','uploading')",
            $migration,
        );
        self::assertStringContainsString(
            "OLD.state = 'uploaded' AND NEW.state IN ('uploaded','referenced','deleting')",
            $migration,
        );
    }

    private function methodSource(string $methodName): string
    {
        $method = new ReflectionMethod(LegalDocumentSignatureService::class, $methodName);
        $source = file(
            dirname(__DIR__, 3).'/app/Services/LegalArchive/Signatures/LegalDocumentSignatureService.php',
        );
        self::assertIsArray($source);

        return implode('', array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
