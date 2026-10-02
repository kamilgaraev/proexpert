<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantOperationsNativeFileMetadataTest extends TestCase
{
    public function test_native_inventory_uses_real_parent_identities_and_only_actual_read_permissions(): void
    {
        self::assertSame(['quality_defect_photo', 'safety_medical_exam', 'warehouse_item_gallery'], Metadata::types());
        foreach (Metadata::definitions() as $definition) { self::assertSame($definition['table'], (new $definition['model'])->getTable()); }
        self::assertSame(['safety-management.view'], Metadata::definitions()['safety_medical_exam']['permissions']);
        self::assertSame(['warehouse.view'], Metadata::definitions()['warehouse_item_gallery']['permissions']);
        self::assertSame('file_id', Metadata::definitions()['warehouse_item_gallery']['identity']);
        self::assertSame('gallery_id', Metadata::definitions()['warehouse_item_gallery']['parent']);
        self::assertSame('native_file.id', AssistantOperationsNativeFileIndexer::mutationColumn('safety_medical_exam', 'file'));
        self::assertSame('native_parent.id', AssistantOperationsNativeFileIndexer::mutationColumn('warehouse_item_gallery', 'warehouse_item_gallery'));
        self::assertSame('native_source.id', AssistantOperationsNativeFileIndexer::mutationColumn('warehouse_item_gallery', 'file'));
        self::assertNull(AssistantOperationsNativeFileIndexer::mutationColumn('quality_defect_photo', 'file'));
    }

    public function test_quality_identity_requires_verified_private_parent_key_sha_size_and_no_fabricated_file(): void
    {
        $source = $this->photo();
        Metadata::assertSource('quality_defect_photo', $source);
        $bad = [
            ['storage_path' => 'https://example.test/'.$source['storage_path']],
            ['storage_path' => 'org-2/quality-control/defects/7/11111111-1111-4111-8111-111111111111.png'],
            ['storage_path' => 'org-1/quality-control/defects/8/11111111-1111-4111-8111-111111111111.png'],
            ['storage_path' => 'org-1/quality-control/defects/7/../private.png'],
            ['storage_identity_verified' => false], ['expected_sha256' => null], ['expected_sha256' => 'abc'],
            ['storage_etag' => ''], ['size_bytes' => Metadata::MAX_BYTES + 1], ['mime_type' => 'text/plain'], ['native_file_id' => 123], ['native_parent_id' => 9],
        ];
        foreach ($bad as $changes) {
            try { Metadata::assertSource('quality_defect_photo', array_replace($source, $changes)); self::fail('Invalid storage identity was accepted.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid', $exception->getMessage()); }
        }
    }

    public function test_gallery_paths_bind_both_parents_and_real_file_identity_is_distinct_from_gallery(): void
    {
        $source = $this->photo() + ['warehouse_id' => 3, 'material_id' => 5];
        $source = array_replace($source, ['id' => 20, 'native_file_id' => 20, 'native_parent_id' => 4,
            'storage_path' => 'org-1/warehouse/balances/warehouse-3/material-5/11111111-1111-4111-8111-111111111111.png']);
        Metadata::assertSource('warehouse_item_gallery', $source);
        self::assertNotSame($source['id'], $source['native_parent_id']);
        foreach ([['warehouse_id' => 9], ['material_id' => 9], ['native_file_id' => 4], ['storage_path' => 'org-1/warehouse/assets/5/photo.png']] as $changes) {
            try { Metadata::assertSource('warehouse_item_gallery', array_replace($source, $changes)); self::fail('Unrelated gallery object was accepted.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid', $exception->getMessage()); }
        }
    }

    public function test_source_version_covers_file_and_parent_rebind_and_normalizes_postgres_boolean(): void
    {
        $source = $this->photo();
        $version = Metadata::versionData('quality_defect_photo', $source);
        self::assertSame('true', $version['storage_identity_verified']);
        self::assertArrayNotHasKey('metadata', $version);
        foreach (['storage_path', 'expected_sha256', 'quality_defect_id', 'parent_project_id', 'parent_updated_at', 'actor_user_id'] as $field) {
            $changed = Metadata::versionData('quality_defect_photo', array_replace($source, [$field => 'changed']));
            self::assertNotSame(Metadata::fingerprint($version), Metadata::fingerprint($changed));
        }
        self::assertArrayHasKey('native_file_id', Metadata::versionExpressions('safety_medical_exam'));
        self::assertArrayHasKey('employee_updated_at', Metadata::versionExpressions('safety_medical_exam'));
        self::assertArrayHasKey('warehouse_updated_at', Metadata::versionExpressions('warehouse_item_gallery'));
    }

    private function photo(): array
    {
        return ['id' => 2, 'organization_id' => 1, 'native_parent_id' => 2, 'native_file_id' => null, 'quality_defect_id' => 7,
            'storage_path' => 'org-1/quality-control/defects/7/11111111-1111-4111-8111-111111111111.png', 'storage_identity_verified' => true,
            'storage_etag' => 'etag', 'expected_sha256' => str_repeat('a', 64), 'size_bytes' => 20, 'mime_type' => 'image/png', 'actor_user_id' => 4, 'parent_project_id' => 9];
    }
}
