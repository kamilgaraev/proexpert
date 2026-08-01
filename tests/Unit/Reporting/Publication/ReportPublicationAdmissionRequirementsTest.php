<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionProfileCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportPublicationAdmissionRequirementsTest extends TestCase
{
    public function test_provider_contracts_match_the_canonical_delivery_source(): void
    {
        $source = dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/report-publication-delivery-contracts.v1.json';
        $decoded = json_decode((string) file_get_contents($source), true, 64, JSON_THROW_ON_ERROR);
        $contracts = ReportPublicationAdmissionRequirements::deliveryContractsByCode()['procurement_cycle'];
        $canonical = $decoded['codes']['procurement_cycle'];

        self::assertSame($canonical['drill_down']['schema_sha256'], $contracts['drill_down_schema_sha256']);
        foreach (['csv', 'pdf', 'xlsx'] as $format) {
            self::assertSame($canonical['exports'][$format]['schema_sha256'], $contracts['exports'][$format]['schema_sha256']);
            self::assertSame($canonical['exports'][$format]['renderer_class'], $contracts['exports'][$format]['renderer_class']);
        }
        self::assertSame(
            hash('sha256', CanonicalJson::encode($canonical['exports'])),
            ReportPublicationAdmissionRequirements::contractHashesByCode()['procurement_cycle']['delivery_contract_sha256'],
        );
        self::assertSame(
            $contracts['drill_down_schema_sha256'],
            ReportPublicationAdmissionRequirements::contractHashesByCode()['procurement_cycle']['drill_contract_sha256'],
        );

        $profile = ReportPublicationAdmissionRequirements::profileCatalog()->forCode('procurement_cycle');
        self::assertSame(ReportPublicationAdmissionRequirements::requiredChecksByCode()['procurement_cycle'], $profile->requiredChecks);
        self::assertSame($canonical['drill_down']['schema_sha256'], $profile->drillDownSchemaHash);
        foreach (['csv', 'pdf', 'xlsx'] as $format) {
            self::assertSame($canonical['exports'][$format]['schema_sha256'], $profile->exports[$format]['schema_sha256']);
            self::assertSame($canonical['exports'][$format]['renderer_class'], $profile->exports[$format]['renderer_class']);
        }
    }

    public function test_budget_plan_fact_profile_requires_only_its_supported_delivery_evidence(): void
    {
        $source = dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/report-publication-delivery-contracts.v1.json';
        $decoded = json_decode((string) file_get_contents($source), true, 64, JSON_THROW_ON_ERROR);
        $canonical = $decoded['codes']['budget_plan_fact'];
        $profile = ReportPublicationAdmissionRequirements::profileCatalog()->forCode('budget_plan_fact');

        self::assertSame([
            'binding_contract',
            'drill_down_contract',
            'export_csv_contract',
            'export_xlsx_contract',
            'formula_contract',
            'postgresql_contract',
            'rbac_contract',
            'source_contract',
        ], $profile->requiredChecks);
        self::assertSame(['csv', 'xlsx'], array_keys($profile->exports));
        self::assertSame($canonical['drill_down']['schema_sha256'], $profile->drillDownSchemaHash);
        self::assertSame(
            hash('sha256', CanonicalJson::encode($canonical['exports'])),
            ReportPublicationAdmissionRequirements::contractHashesByCode()['budget_plan_fact']['delivery_contract_sha256'],
        );
    }

    public function test_tampered_contract_source_is_rejected(): void
    {
        $source = dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/report-publication-delivery-contracts.v1.json';
        $tampered = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-contract-'.bin2hex(random_bytes(8)).'.json';
        $bytes = file_get_contents($source);
        self::assertIsString($bytes);
        file_put_contents($tampered, str_replace('a6a14ce7f1fd0db3727eb92f1bd43dd325a90874bee5b250695927bf75540dfe', str_repeat('0', 64), $bytes));
        try {
            $this->expectException(RuntimeException::class);
            ReportPublicationAdmissionRequirements::validateFile($tampered);
        } finally {
            @unlink($tampered);
        }
    }

    public function test_tampered_renderer_or_format_is_rejected(): void
    {
        $source = dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/report-publication-delivery-contracts.v1.json';
        $bytes = file_get_contents($source);
        self::assertIsString($bytes);
        $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $decoded['codes']['procurement_cycle']['exports']['csv']['renderer_class'] = 'App\\FakeRenderer';
        $tampered = tempnam(sys_get_temp_dir(), 'most-report-contract-');
        self::assertIsString($tampered);
        file_put_contents($tampered, json_encode($decoded, JSON_THROW_ON_ERROR));
        try {
            $this->expectException(RuntimeException::class);
            ReportPublicationAdmissionRequirements::validateFile($tampered);
        } finally {
            @unlink($tampered);
        }
    }

    public function test_empty_profile_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_admission_profile_invalid');

        new ReportPublicationAdmissionProfile('procurement_cycle', [], str_repeat('a', 64), []);
    }

    public function test_duplicate_profiles_are_rejected(): void
    {
        $profile = new ReportPublicationAdmissionProfile(
            'procurement_cycle',
            ['binding_contract', 'drill_down_contract', 'export_xlsx_contract', 'formula_contract', 'rbac_contract', 'source_contract'],
            str_repeat('a', 64),
            ['xlsx' => ['schema_sha256' => str_repeat('b', 64), 'renderer_class' => XlsxReportExportRenderer::class]],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_admission_profile_catalog_invalid');

        new ReportPublicationAdmissionProfileCatalog([$profile, $profile]);
    }

    public function test_unknown_profile_lookup_is_rejected_without_a_default(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        ReportPublicationAdmissionRequirements::profileCatalog()->forCode('unknown_report');
    }

    public function test_unknown_required_check_is_rejected_when_building_a_profile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_admission_profile_invalid');

        new ReportPublicationAdmissionProfile(
            'procurement_cycle',
            ['binding_contract', 'drill_down_contract', 'export_xlsx_contract', 'formula_contract', 'rbac_contract', 'source_contract', 'unknown_contract'],
            str_repeat('a', 64),
            ['xlsx' => ['schema_sha256' => str_repeat('b', 64), 'renderer_class' => XlsxReportExportRenderer::class]],
        );
    }
}
