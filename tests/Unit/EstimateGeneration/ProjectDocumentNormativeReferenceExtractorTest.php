<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class ProjectDocumentNormativeReferenceExtractorTest extends TestCase
{
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

    private function extractor(): ProjectDocumentNormativeReferenceExtractor
    {
        return new ProjectDocumentNormativeReferenceExtractor();
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
