<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\OrganizationStoragePath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationStoragePathTest extends TestCase
{
    public function test_builds_strict_domain_and_personal_paths(): void
    {
        self::assertSame(
            'org-42/reports/exports/01J4EXPORT/01J4OBJECT.xlsx',
            OrganizationStoragePath::forDomain(
                42,
                'reports',
                'exports/01J4EXPORT',
                '01J4OBJECT',
                'xlsx',
            ),
        );
        self::assertSame(
            'org-42/personal-files/user-7/018f4a8a-0000-7000-8000-000000000001.pdf',
            OrganizationStoragePath::personal(
                42,
                7,
                '018f4a8a-0000-7000-8000-000000000001',
                'pdf',
            ),
        );
        self::assertSame(
            'org-42/procurement/purchase-orders/user-7/order-15/01J5ORDER.pdf',
            OrganizationStoragePath::forDomain(
                42,
                'procurement',
                'purchase-orders/user-7/order-15',
                '01J5ORDER',
                'pdf',
            ),
        );
        self::assertSame(
            'org-42/warehouse/exports/user-7/custody/summary/01J5EXPORT.xlsx',
            OrganizationStoragePath::forDomain(
                42,
                'warehouse',
                'exports/user-7/custody/summary',
                '01J5EXPORT',
                'xlsx',
            ),
        );
    }

    public function test_builds_actor_and_system_domain_paths(): void
    {
        self::assertSame(
            'org-42/reports/exports/01J4EXPORT/user-7/artifact.xlsx',
            OrganizationStoragePath::forActor(
                42,
                'reports',
                'exports/01J4EXPORT',
                7,
                'artifact',
                'xlsx',
            ),
        );
        self::assertSame(
            'org-42/estimate-generation/01J4SESSION/vision/v1/user-system/01J4OBJECT.png',
            OrganizationStoragePath::forActor(
                42,
                'estimate-generation',
                '01J4SESSION/vision/v1',
                null,
                '01J4OBJECT',
                'png',
            ),
        );
    }

    #[DataProvider('invalidStrictPathProvider')]
    public function test_rejects_invalid_strict_path(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('organization_storage_path_invalid');

        $build();
    }

    public static function invalidStrictPathProvider(): iterable
    {
        yield 'organization zero' => [
            static fn (): string => OrganizationStoragePath::forDomain(0, 'reports', 'exports/01J4', '01J5', 'xlsx'),
        ];
        yield 'user zero' => [
            static fn (): string => OrganizationStoragePath::personal(42, 0, '01J5', 'pdf'),
        ];
        yield 'unknown domain' => [
            static fn (): string => OrganizationStoragePath::forDomain(42, 'cms', 'pages', '01J5', 'json'),
        ];
        yield 'personal domain bypass' => [
            static fn (): string => OrganizationStoragePath::forDomain(42, 'personal-files', 'other-user', '01J5', 'pdf'),
        ];
        yield 'parent traversal' => [
            static fn (): string => OrganizationStoragePath::forDomain(42, 'reports', '../exports', '01J5', 'xlsx'),
        ];
        yield 'slash in object id' => [
            static fn (): string => OrganizationStoragePath::forDomain(42, 'reports', 'exports', '01J5/file', 'xlsx'),
        ];
        yield 'extension with dot' => [
            static fn (): string => OrganizationStoragePath::forDomain(42, 'reports', 'exports', '01J5', '.xlsx'),
        ];
        yield 'actor zero' => [
            static fn (): string => OrganizationStoragePath::forActor(42, 'reports', 'exports', 0, '01J5', 'xlsx'),
        ];
    }

    public function test_adds_organization_prefix_to_relative_path(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::forOrganization(39, 'reports/project_profitability_report.pdf')
        );
    }

    public function test_does_not_duplicate_existing_organization_prefix(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::forOrganization(39, 'org-39/reports/project_profitability_report.pdf')
        );
    }

    public function test_normalizes_legacy_report_path(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::normalizeLegacyPath(39, 'reports/39/project_profitability_report.pdf')
        );
    }

    public function test_normalizes_legacy_import_path(): void
    {
        $this->assertSame(
            'org-39/estimate-imports/source.xlsx',
            OrganizationStoragePath::normalizeLegacyPath(39, 'estimate-imports/org-39/source.xlsx')
        );
    }
}
