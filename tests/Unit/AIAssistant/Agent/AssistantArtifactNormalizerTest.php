<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use PHPUnit\Framework\TestCase;

final class AssistantArtifactNormalizerTest extends TestCase
{
    public function test_extracts_pdf_artifact_from_tool_result(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $artifacts = $normalizer->fromToolResult('generate_project_timelines_report', [
            'pdf_url' => 'https://storage.example.test/reports/project.pdf',
            'filename' => 'project.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-1/reports/project.pdf',
            'expires_at' => '2026-05-04T12:00:00+00:00',
        ]);

        $this->assertSame([
            [
                'type' => 'pdf',
                'url' => 'https://storage.example.test/reports/project.pdf',
                'filename' => 'project.pdf',
                'source_tool' => 'generate_project_timelines_report',
                'storage_disk' => 's3',
                'storage_path' => 'org-1/reports/project.pdf',
                'expires_at' => '2026-05-04T12:00:00+00:00',
            ],
        ], $artifacts);
    }

    public function test_extracts_excel_artifact_from_tool_result(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $artifacts = $normalizer->fromToolResult('generate_material_movements_report', [
            'excel_url' => 'https://storage.example.test/reports/materials.xlsx',
            'storage_disk' => 's3',
            'storage_path' => 'org-1/reports/materials.xlsx',
        ]);

        $this->assertSame('excel', $artifacts[0]['type']);
        $this->assertSame('materials.xlsx', $artifacts[0]['filename']);
    }

    public function test_ignores_placeholder_urls(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $artifacts = $normalizer->fromToolResult('generate_project_timelines_report', [
            'pdf_url' => 'реальный_pdf_url_из_данных',
            'download_url' => 'https://example.com/fake/report.pdf',
            'file_url' => 'https://storage.example.test/placeholder/report.pdf',
            'excel_url' => 'ТУТ_ССЫЛКА',
        ]);

        $this->assertSame([], $artifacts);
    }

    public function test_extracts_nested_artifacts_recursively(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $artifacts = $normalizer->fromToolResult('generate_nested_report', [
            'data' => [
                'report' => [
                    'download_url' => 'https://storage.example.test/reports/nested.pdf',
                    'storage_disk' => 's3',
                    'storage_path' => 'org-1/reports/nested.pdf',
                ],
            ],
        ]);

        $this->assertCount(1, $artifacts);
        $this->assertSame('file', $artifacts[0]['type']);
        $this->assertSame('nested.pdf', $artifacts[0]['filename']);
        $this->assertSame('org-1/reports/nested.pdf', $artifacts[0]['storage_path']);
    }

    public function test_non_array_result_is_ignored(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $this->assertSame([], $normalizer->fromToolResult('generate_report', 'https://storage.example.test/report.pdf'));
    }

    public function test_filename_is_derived_from_url_path(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $artifacts = $normalizer->fromToolResult('generate_report', [
            'file_url' => 'https://storage.example.test/org-1/reports/report-final.pdf?X-Amz-Signature=abc',
            'storage_disk' => 's3',
            'storage_path' => 'org-1/reports/report-final.pdf',
        ]);

        $this->assertSame('report-final.pdf', $artifacts[0]['filename']);
    }

    public function test_external_url_without_storage_evidence_is_ignored(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $this->assertSame([], $normalizer->fromToolResult('generate_report', [
            'file_url' => 'https://external.example.test/reports/report.pdf',
        ]));
    }

    public function test_storage_path_must_belong_to_organization_reports_folder(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $this->assertSame([], $normalizer->fromToolResult('generate_report', [
            'file_url' => 'https://storage.example.test/reports/report.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'tmp/report.pdf',
        ]));
    }

    public function test_storage_disk_must_be_s3(): void
    {
        $normalizer = new AssistantArtifactNormalizer;

        $this->assertSame([], $normalizer->fromToolResult('generate_report', [
            'file_url' => 'https://storage.example.test/reports/report.pdf',
            'storage_path' => 'org-1/reports/report.pdf',
        ]));

        $this->assertSame([], $normalizer->fromToolResult('generate_report', [
            'file_url' => 'https://storage.example.test/reports/report.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'org-1/reports/report.pdf',
        ]));
    }
}
