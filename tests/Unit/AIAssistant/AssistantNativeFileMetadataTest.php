<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantNativeFileMetadataTest extends TestCase
{
    public function test_native_path_is_bound_to_current_organization_period_package_and_declared_filename(): void
    {
        $key = '20260929140000-0123456789abcdef';
        $source = ['organization_id' => 7, 'export_package_id' => 5, 'file_type' => 'source_csv', 'file_name' => 'payroll-source.csv',
            'storage_disk' => 's3', 'size_bytes' => 12, 'storage_path' => 'org-7/workforce/payroll-exports/period-3/package-'.$key.'/payroll-source.csv'];
        $package = ['id' => 5, 'organization_id' => 7, 'payroll_period_id' => 3, 'package_number' => 'WF-3-'.$key, 'source_hash' => hash('sha256', 'source')];
        $period = ['id' => 3, 'organization_id' => 7];
        AssistantNativeFileMetadata::assertSource($source, $package, $period);
        self::assertSame(['workforce_export_package_file'], AssistantNativeFileMetadata::types());
        foreach (['https://external.test/file.csv', '/absolute/file.csv', 'org-7/../file.csv', 'org-8/workforce/payroll-exports/period-3/package-'.$key.'/payroll-source.csv',
            'org-7/workforce/payroll-exports/period-3/package-'.$key.'/payroll-source.csv?token=secret'] as $path) {
            try { AssistantNativeFileMetadata::assertSource(array_replace($source, ['storage_path' => $path]), $package, $period); self::fail('Unsafe native path.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid', $exception->getMessage()); }
        }
    }
}
