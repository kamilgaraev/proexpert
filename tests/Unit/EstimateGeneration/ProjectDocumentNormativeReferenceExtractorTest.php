<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class ProjectDocumentNormativeReferenceExtractorTest extends TestCase
{
    public function test_sewerage_reference_keeps_its_category_in_specific_and_combined_packages(): void
    {
        $analysis = ['source_documents' => [['id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'text' => 'ФЕР 08-02-001-01 Прокладка труб канализации 10 м']]];
        foreach (['sewerage' => 'sewerage', 'custom-sewerage' => 'sewerage', 'water_sewerage' => 'plumbing', 'engineering' => 'engineering'] as $key => $scope) {
            $rows = $this->extractor()->extract($analysis, ['key' => $key, 'scope_type' => $scope], ['construction_part' => $scope]);
            self::assertCount(1, $rows, $key);
            self::assertSame('sewerage', $rows[0]['work_category']);
        }
        self::assertSame([], $this->extractor()->extract($analysis, ['key' => 'plumbing', 'scope_type' => 'plumbing'], ['construction_part' => 'plumbing']));
    }

    public function test_reference_limit_accepts_exact_count_and_preserves_mixed_line_endings(): void
    {
        $line = 'ФЕР 08-02-001-01 Кладка стен 10 м2';
        $document = ['id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'text' => "\r\n".$line."\r\r\n".$line."\n"];
        $references = $this->extractor()->extract(['source_documents' => [$document, $document]], $this->localEstimate(), $this->section(), 2);
        self::assertCount(2, $references);
        self::assertSame([2, 4], array_map(static fn (array $row): int => $row['source_refs'][0]['line_number'], $references));
        self::assertNotSame($references[0]['metadata']['source_observation_id'], $references[1]['metadata']['source_observation_id']);
    }

    public function test_reference_limit_rejects_overflow_without_truncating(): void
    {
        $document = ['id' => 82, 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'text' => str_repeat("ФЕР 08-02-001-01 Кладка стен 10 м2\n", 3)];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        $this->extractor()->extract(['source_documents' => [$document]], $this->localEstimate(), $this->section(), 2);
    }

    public function test_identical_norm_rows_preserve_distinct_document_and_text_line_origins(): void
    {
        $line = 'ФЕР 08-02-001-01 Кладка стен 10 м2';
        $document = ['id' => 82, 'filename' => 'volumes.pdf', 'status' => 'ready',
            'quality' => ['level' => 'good'], 'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'source_version' => 'sha256:'.str_repeat('a', 64), 'text' => "\n".$line."\n\n".$line];
        $other = [...$document, 'id' => 83, 'text' => $line];
        $revised = [...$document, 'source_version' => 'sha256:'.str_repeat('b', 64)];
        $rows = $this->extractor()->extract(['source_documents' => [$document, $document, $other, $revised]],
            $this->localEstimate(), $this->section());

        self::assertCount(5, $rows);
        self::assertSame([82, 82, 83, 82, 82], array_map(static fn (array $row): int => $row['source_refs'][0]['document_id'], $rows));
        self::assertSame([2, 4, 1, 2, 4], array_map(static fn (array $row): int => $row['source_refs'][0]['line_number'], $rows));
        self::assertSame($revised['source_version'], $rows[4]['source_refs'][0]['source_version']);
    }

    public function test_reference_estimate_is_not_used_as_normative_reference_source(): void
    {
        $references = $this->extractor()->extract([
            'source_documents' => [[
                'id' => 81,
                'filename' => 'grand-smeta-reference.pdf',
                'status' => 'ready',
                'quality' => ['level' => 'good'],
                'text' => 'ФЕР 08-02-001-01 Кладка стен 10 м2',
                'document_understanding' => [
                    'role_for_estimation' => 'reference_estimate',
                ],
            ]],
        ], $this->localEstimate(), $this->section());

        self::assertSame([], $references);
    }

    public function test_quantity_source_document_can_create_normative_reference(): void
    {
        $references = $this->extractor()->extract([
            'source_documents' => [[
                'id' => 82,
                'filename' => 'work-volume-statement.pdf',
                'status' => 'ready',
                'quality' => ['level' => 'good'],
                'text' => 'ФЕР 08-02-001-01 Кладка стен 10 м2',
                'document_understanding' => [
                    'role_for_estimation' => 'quantity_source',
                ],
            ]],
        ], $this->localEstimate(), $this->section());

        self::assertCount(1, $references);
        self::assertSame('08-02-001-01', $references[0]['normative_rate_code']);
        self::assertSame(10.0, $references[0]['quantity']);
        self::assertSame('м2', $references[0]['unit']);
        self::assertSame(82, $references[0]['source_refs'][0]['document_id']);
    }

    public function test_quantity_source_document_preserves_fsbc_resource_reference_without_work_norm(): void
    {
        $references = $this->extractor()->extract([
            'source_documents' => [[
                'id' => 83,
                'filename' => 'material-specification.pdf',
                'status' => 'ready',
                'quality' => ['level' => 'good'],
                'text' => 'ФСБЦ 01.1.01.01-0001 Бетон тяжелый 12 м3',
                'document_understanding' => [
                    'role_for_estimation' => 'quantity_source',
                ],
            ]],
        ], [
            'key' => 'materials',
            'scope_type' => 'custom',
        ], [
            'construction_part' => 'custom',
        ]);

        self::assertCount(1, $references);
        self::assertNull($references[0]['normative_rate_code']);
        self::assertSame('01.1.01.01-0001', $references[0]['metadata']['normative_resource_code']);
        self::assertSame('fsbc_resource', $references[0]['metadata']['normative_reference_kind']);
        self::assertTrue($references[0]['metadata']['requires_work_norm_selection']);
        self::assertContains('normative_code_required', $references[0]['validation_flags']);
        self::assertSame(12.0, $references[0]['quantity']);
        self::assertSame('м3', $references[0]['unit']);
        self::assertSame(83, $references[0]['source_refs'][0]['document_id']);
    }

    private function extractor(): ProjectDocumentNormativeReferenceExtractor
    {
        return new ProjectDocumentNormativeReferenceExtractor;
    }

    /**
     * @return array<string, mixed>
     */
    private function localEstimate(): array
    {
        return [
            'key' => 'walls',
            'scope_type' => 'walls',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(): array
    {
        return [
            'construction_part' => 'walls',
        ];
    }
}
