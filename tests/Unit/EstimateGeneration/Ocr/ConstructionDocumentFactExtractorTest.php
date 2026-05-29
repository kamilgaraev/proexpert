<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\ExtractedDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\ConstructionDocumentFactExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentFactMerger;
use Tests\TestCase;

class ConstructionDocumentFactExtractorTest extends TestCase
{
    public function test_it_extracts_core_construction_facts_from_ocr_text(): void
    {
        $result = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 3,
                    text: implode("\n", [
                        'Общая площадь здания 1280 м2',
                        'Складская зона 980 м2',
                        'Габариты 24 x 36',
                        '2 этажа, высота 4.2 м',
                        'Электроснабжение, вентиляция, пожарная сигнализация',
                    ]),
                    confidence: 0.88,
                ),
            ],
        );

        $facts = app(ConstructionDocumentFactExtractor::class)->extract($result, 15, 'plan.pdf');
        $types = array_map(static fn (ExtractedDocumentFact $fact): string => $fact->factType, $facts);

        $this->assertContains('total_area', $types);
        $this->assertContains('zone_area', $types);
        $this->assertContains('dimension', $types);
        $this->assertContains('floor_count', $types);
        $this->assertContains('height', $types);
        $this->assertContains('engineering_system', $types);

        $totalArea = collect($facts)->firstWhere('factType', 'total_area');

        $this->assertSame(1280.0, $totalArea->valueNumber);
        $this->assertSame(3, $totalArea->sourceRef['page_number']);
        $this->assertSame('plan.pdf', $totalArea->sourceRef['filename']);
    }

    public function test_it_uses_total_area_after_parenthetical_terrace_with_split_unit(): void
    {
        $result = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 2,
                    text: implode("\n", [
                        '1. Общая площадь дома (в т.ч. терраса 22,15 кв.м.) - 151,76 м',
                        '2',
                        '2. Жилая площадь - 80,21 м',
                        '2',
                    ]),
                    confidence: 1.0,
                ),
            ],
        );

        $facts = app(ConstructionDocumentFactExtractor::class)->extract($result, 21, 'house.pdf');
        $summary = app(DocumentFactMerger::class)->summarize($facts);
        $totalAreaValues = array_map(
            static fn (ExtractedDocumentFact $fact): ?float => $fact->factType === 'total_area' ? $fact->valueNumber : null,
            $facts,
        );

        $this->assertSame(151.76, $summary['total_area_m2']);
        $this->assertNotContains(22.15, array_values(array_filter($totalAreaValues)));
    }

    public function test_fact_merger_summarizes_values_and_conflicts(): void
    {
        $facts = [
            new ExtractedDocumentFact(
                factType: 'total_area',
                label: 'Общая площадь',
                confidence: 0.9,
                scopeKey: 'total_area',
                valueNumber: 1200.0,
                unit: 'м2',
                sourceRef: ['page_number' => 1],
            ),
            new ExtractedDocumentFact(
                factType: 'total_area',
                label: 'Итого',
                confidence: 0.8,
                scopeKey: 'total_area',
                valueNumber: 1280.0,
                unit: 'м2',
                sourceRef: ['page_number' => 2],
            ),
            new ExtractedDocumentFact(
                factType: 'engineering_system',
                label: 'вентиляция',
                confidence: 0.7,
                scopeKey: 'ventilation',
                sourceRef: ['page_number' => 1],
            ),
        ];

        $summary = app(DocumentFactMerger::class)->summarize($facts);

        $this->assertSame(1200.0, $summary['total_area_m2']);
        $this->assertCount(1, $summary['engineering_systems']);
        $this->assertSame('total_area_m2', $summary['conflicts'][0]['field']);
        $this->assertCount(2, $summary['conflicts'][0]['values']);
    }
}
