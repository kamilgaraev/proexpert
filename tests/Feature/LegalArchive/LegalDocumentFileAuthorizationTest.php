<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LegalDocumentFileAuthorizationTest extends TestCase
{
    public function test_attached_file_create_requires_upload_and_version_permissions(): void
    {
        $request = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveDocumentRequest.php');

        self::assertIsString($request);
        self::assertStringContainsString("hasFile('file')", $request);
        self::assertStringContainsString("'legal_archive.files.upload'", $request);
        self::assertStringContainsString("'legal_archive.versions.create'", $request);
    }

    public function test_generic_document_mutation_cannot_assign_retention_or_legal_hold(): void
    {
        $store = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveDocumentRequest.php');
        $update = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/UpdateLegalArchiveDocumentRequest.php');

        foreach ([$store, $update] as $source) {
            self::assertIsString($source);
            self::assertStringNotContainsString("'retention_policy'", $source);
            self::assertStringNotContainsString("'retention_basis'", $source);
            self::assertStringNotContainsString("'retention_started_at'", $source);
            self::assertStringNotContainsString("'retention_until'", $source);
            self::assertStringNotContainsString("'legal_hold'", $source);
        }

        $document = new LegalArchiveDocument;
        foreach (['retention_policy', 'retention_basis', 'retention_started_at', 'retention_until', 'legal_hold'] as $attribute) {
            self::assertFalse($document->isFillable($attribute));
        }

        $reflection = new \ReflectionClass(LegalArchiveRegistryService::class);
        $registry = $reflection->newInstanceWithoutConstructor();
        $payload = $reflection->getMethod('documentPayload')->invoke($registry, 10, 30, [
            'title' => 'Документ',
            'retention_policy' => 'permanent',
            'retention_basis' => 'law',
            'retention_started_at' => '2026-01-01',
            'retention_until' => '2031-01-01',
            'legal_hold' => true,
        ]);

        self::assertArrayNotHasKey('retention_policy', $payload);
        self::assertArrayNotHasKey('retention_basis', $payload);
        self::assertArrayNotHasKey('retention_started_at', $payload);
        self::assertArrayNotHasKey('retention_until', $payload);
        self::assertArrayNotHasKey('legal_hold', $payload);
    }

    public function test_governance_mutations_have_dedicated_permission_boundary(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalDocumentGovernanceService.php');

        self::assertIsString($service);
        self::assertStringContainsString("'legal_archive.retention.manage'", $service);
        self::assertStringContainsString("'legal_archive.legal_hold.manage'", $service);
        self::assertStringContainsString('updateRetention(', $service);
        self::assertStringContainsString('setLegalHold(', $service);
    }

    public function test_returns_short_lived_url_for_ready_version_owned_by_actor_tenant(): void
    {
        [$version, $actor] = $this->versionAndActor(10, 10, 'ready');
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $auditRecorded = false;
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('temporaryUrl')
            ->with('org-10/legal-archive/files/7/version.pdf', 5, self::isInstanceOf(Organization::class))
            ->willReturnCallback(function () use (&$auditRecorded): string {
                self::assertTrue($auditRecorded, 'Audit/outbox unit must commit before issuing a signed URL.');

                return 'https://signed.example/version.pdf';
            });
        $audit = $this->createMock(LegalDocumentAudit::class);
        $audit->expects(self::once())->method('record')
            ->with('preview', self::isInstanceOf(LegalArchiveDocument::class), $actor, self::isType('array'))
            ->willReturnCallback(function () use (&$auditRecorded): void {
                $auditRecorded = true;
            });

        $url = (new LegalDocumentDownloadService(
            $storage,
            $authorization,
            new LegalDocumentFilePolicy([]),
            new NullLogger,
            $audit,
        ))->temporaryUrl($version, $actor, 'preview');

        self::assertSame('https://signed.example/version.pdf', $url);
    }

    #[DataProvider('forbiddenVersionProvider')]
    public function test_denies_cross_tenant_unready_or_unknown_purpose(
        int $versionOrganizationId,
        int $actorOrganizationId,
        string $processingStatus,
        string $purpose,
    ): void {
        [$version, $actor] = $this->versionAndActor($versionOrganizationId, $actorOrganizationId, $processingStatus);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('temporaryUrl');

        $this->expectException(AuthorizationException::class);

        (new LegalDocumentDownloadService(
            $storage,
            $authorization,
            new LegalDocumentFilePolicy([]),
            new NullLogger,
            $this->createMock(LegalDocumentAudit::class),
        ))->temporaryUrl($version, $actor, $purpose);
    }

    /** @return iterable<string, array{int, int, string, string}> */
    public static function forbiddenVersionProvider(): iterable
    {
        yield 'foreign tenant' => [11, 10, 'ready', 'download'];
        yield 'quarantine' => [10, 10, 'quarantine', 'preview'];
        yield 'failed scan' => [10, 10, 'failed', 'download'];
        yield 'unknown purpose' => [10, 10, 'ready', 'share'];
    }

    public function test_denies_actor_without_view_permission(): void
    {
        [$version, $actor] = $this->versionAndActor(10, 10, 'ready');
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(false);
        $storage = $this->createMock(FileService::class);

        $this->expectException(AuthorizationException::class);

        (new LegalDocumentDownloadService(
            $storage,
            $authorization,
            new LegalDocumentFilePolicy([]),
            new NullLogger,
            $this->createMock(LegalDocumentAudit::class),
        ))->temporaryUrl($version, $actor, 'download');
    }

    /** @return array{LegalArchiveDocumentVersion, User} */
    private function versionAndActor(int $versionOrganizationId, int $actorOrganizationId, string $status): array
    {
        $organization = new Organization;
        $organization->forceFill(['id' => $versionOrganizationId]);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 5, 'organization_id' => $versionOrganizationId]);
        $file = new LegalArchiveDocumentFile;
        $file->forceFill(['id' => 7, 'document_id' => 5, 'organization_id' => $versionOrganizationId]);
        $file->setRelation('document', $document);
        $version = new LegalArchiveDocumentVersion;
        $version->forceFill([
            'id' => 9,
            'document_id' => 5,
            'document_file_id' => 7,
            'organization_id' => $versionOrganizationId,
            'processing_status' => $status,
            'file_path' => "org-{$versionOrganizationId}/legal-archive/files/7/version.pdf",
        ]);
        $version->setRelation('documentFile', $file);
        $version->setRelation('organization', $organization);
        $actor = new User;
        $actor->forceFill(['id' => 30, 'current_organization_id' => $actorOrganizationId]);

        return [$version, $actor];
    }
}
