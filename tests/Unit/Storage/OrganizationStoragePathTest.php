<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\OrganizationStoragePath;
use PHPUnit\Framework\TestCase;

class OrganizationStoragePathTest extends TestCase
{
    public function testAddsOrganizationPrefixToRelativePath(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::forOrganization(39, 'reports/project_profitability_report.pdf')
        );
    }

    public function testDoesNotDuplicateExistingOrganizationPrefix(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::forOrganization(39, 'org-39/reports/project_profitability_report.pdf')
        );
    }

    public function testNormalizesLegacyReportPath(): void
    {
        $this->assertSame(
            'org-39/reports/project_profitability_report.pdf',
            OrganizationStoragePath::normalizeLegacyPath(39, 'reports/39/project_profitability_report.pdf')
        );
    }

    public function testNormalizesLegacyImportPath(): void
    {
        $this->assertSame(
            'org-39/estimate-imports/source.xlsx',
            OrganizationStoragePath::normalizeLegacyPath(39, 'estimate-imports/org-39/source.xlsx')
        );
    }
}
