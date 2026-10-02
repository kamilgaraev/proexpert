<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileMetadata as Metadata;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Storage\FileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantSalesNativeFileMetadataTest extends TestCase
{
    public function test_all_five_actual_native_sources_have_finite_owned_paths_and_distinct_byte_proofs(): void
    {
        self::assertCount(5, Metadata::definitions());
        foreach ($this->sources() as $type => $source) {
            Metadata::assertSource($type, $source);
            self::assertStringStartsWith('org-7/', $source[Metadata::definitions()[$type]['path']]);
            self::assertSame($type, Metadata::versionData($type, $source)['native_entity_type']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Metadata::fingerprint(Metadata::versionData($type, $source)));
        }
        self::assertNull(Metadata::definitions()['commercial_proposal_export']['hash']);
        self::assertSame('sha256', Metadata::definitions()['purchase_order']['hash']);
        self::assertSame('application/xml', Metadata::mime('purchase_receipt_document', $this->sources()['purchase_receipt_document']));
        self::assertArrayNotHasKey('presale_estimate', Metadata::definitions());
    }

    public function test_foreign_paths_traversal_changed_owner_and_absent_procurement_blob_proof_are_rejected(): void
    {
        foreach ($this->sources() as $type => $source) {
            $field = Metadata::definitions()[$type]['path'];
            foreach (['org-8/'.substr($source[$field], 6), str_replace('org-7/', 'org-7/../', $source[$field]), 'https://example.test/file.pdf', 'org-7/private/unknown.pdf'] as $path) {
                $this->invalid($type, array_replace($source, [$field => $path]));
            }
        }
        $source = $this->sources()['purchase_order'];
        $this->invalid('purchase_order', array_replace($source, ['sha256' => null]));
        $this->invalid('purchase_order', array_replace($source, ['size_bytes' => 0]));
        $this->invalid('purchase_order', array_replace($source, ['id' => 44]));
        $this->invalid('purchase_receipt_document', array_replace($this->sources()['purchase_receipt_document'], ['purchase_order_id' => 44]));
        $this->invalid('commercial_proposal_export', array_replace($this->sources()['commercial_proposal_export'], ['status' => 'pending']));
    }

    public function test_version_receipt_changes_for_real_native_path_status_parent_and_byte_hash(): void
    {
        foreach ($this->sources() as $type => $source) {
            $version = Metadata::versionData($type, $source);
            $hash = Metadata::fingerprint($version);
            $field = Metadata::definitions()[$type]['path'];
            self::assertNotSame($hash, Metadata::fingerprint(Metadata::versionData($type, array_replace($source, [$field => 'changed']))));
            self::assertNotSame($hash, Metadata::fingerprint(Metadata::versionData($type, array_replace($source, ['updated_at' => '2026-10-01 01:00:00']))));
        }
        self::assertSame(null, Metadata::projectId([]));
        self::assertSame(22, Metadata::projectId(['parent_project_id' => '22']));
    }

    public function test_current_financial_revocation_denies_before_any_storage_or_source_query(): void
    {
        $policy = (new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $actor, string $permission): bool => $permission !== 'finance.view');
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('readCurrentBounded');
        $adapter = new AssistantSalesNativeFileAdapter($policy, $authorization, $storage);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_access_denied');
        $adapter->map(new User(['id' => 1]), 7, 'purchase_order', 3);
    }

    public function test_unknown_parent_denies_before_any_storage_or_source_query(): void
    {
        $policy = (new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::never())->method('canCurrent');
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('readCurrentBounded');
        $adapter = new AssistantSalesNativeFileAdapter($policy, $authorization, $storage);
        $document = new AIAssistantDocument(['parent_entity_type' => 'unknown_native_owner', 'parent_entity_id' => 'real-uuid']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_access_denied');
        $adapter->content(new User(['id' => 1]), 7, $document);
    }

    public function test_indexing_mutation_selectors_are_finite_and_never_turn_unknown_entities_into_full_org_scans(): void
    {
        $class = \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileIndexer::class;
        foreach (Metadata::types() as $type) {
            self::assertSame('native_source.id', $class::mutationColumn($type, $type));
            self::assertNull($class::mutationColumn($type, 'presale_estimate'));
            self::assertNull($class::mutationColumn($type, 'unknown'));
        }
        self::assertSame('native_source.commercial_proposal_id', $class::mutationColumn('commercial_proposal_file', 'commercial_proposal'));
        self::assertSame('native_source.commercial_proposal_version_id', $class::mutationColumn('commercial_proposal_export', 'commercial_proposal_version'));
        self::assertSame('native_source.purchase_order_id', $class::mutationColumn('purchase_receipt_document', 'purchase_order'));
        self::assertSame('native_source.purchase_receipt_id', $class::mutationColumn('purchase_receipt_document', 'purchase_receipt'));
        self::assertNull($class::mutationColumn('crm_import_batch', 'crm_company'));
    }

    private function invalid(string $type, array $source): void
    {
        try { Metadata::assertSource($type, $source); self::fail('Untrusted native source accepted: '.$type); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid', $exception->getMessage()); }
    }

    private function sources(): array
    {
        $uuid = '00000000-0000-4000-8000-000000000001';
        $proposal = '00000000-0000-4000-8000-000000000002';
        $version = '00000000-0000-4000-8000-000000000003';
        $base = ['id' => $uuid, 'organization_id' => 7, 'commercial_proposal_id' => $proposal, 'commercial_proposal_version_id' => $version, 'updated_at' => '2026-09-29 01:00:00'];
        return [
            'commercial_proposal_file' => $base + ['category' => 'attachment', 'storage_path' => "org-7/commercial-proposals/$proposal/versions/$version/attachment/$uuid.pdf", 'size_bytes' => 10, 'checksum' => null],
            'commercial_proposal_export' => $base + ['storage_path' => "org-7/commercial-proposals/$proposal/versions/$version/generated_export/qa-v1-0123456789ab.pdf", 'status' => 'ready'],
            'crm_import_batch' => ['id' => $uuid, 'organization_id' => 7, 'stored_path' => "org-7/crm/imports/$uuid.csv"],
            'purchase_receipt_document' => ['id' => 9, 'organization_id' => 7, 'purchase_order_id' => 3, 'storage_key' => "org-7/procurement/receipt-documents/3/$uuid.xml", 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'document_type' => 'upd_xml', 'status' => 'validated'],
            'purchase_order' => ['id' => 3, 'organization_id' => 7, 'storage_path' => "org-7/procurement/purchase-orders/user-1/order-3/$uuid.pdf", 'size_bytes' => 10, 'sha256' => str_repeat('b', 64)],
        ];
    }
}
