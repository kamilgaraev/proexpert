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

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
