<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;

final readonly class CurrentBaselineBenchmarkAdapter implements BenchmarkPipelineAdapter
{
    public function __construct(
        private BenchmarkObjectReader $objects,
        private PdfTextLayerExtractor $pdfText,
        private DrawingGeometryAnalyzer $drawing,
    ) {}

    public function id(): string
    {
        return 'current-baseline';
    }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        if ($case->sourceType !== BenchmarkSourceType::VectorPdf) {
            return BenchmarkPipelineResultData::unsupported('source_type_unsupported');
        }
        $recognition = $this->pdfText->extract(
            $this->objects->read($case, 'input', 64_000_000),
            $case->id.'.pdf',
        );
        if ($recognition === null) {
            return BenchmarkPipelineResultData::technicalFailure('baseline_pdf_unreadable');
        }
        $geometry = $this->drawing->analyze(0, $case->id.'.pdf', $recognition);
        if ($geometry['review_reasons'] !== []) {
            return BenchmarkPipelineResultData::technicalFailure('normalized_building_model_required');
        }
        $analysis = new DrawingAnalysisResultData([], array_map(static fn (array $quantity): array => [
            'scope_key' => $quantity['key'], 'name' => $quantity['key'],
            'quantity' => $quantity['amount'], 'source_refs' => $quantity['evidence_ids'],
        ], $geometry['quantities']), ['document_profile' => ['document_role' => 'normalized_building_model']]);

        return BenchmarkPipelineResultData::success(
            $this->prediction($analysis),
            ['drawing' => 'normalized-building-model:v1', 'pdf_text' => 'smalot-pdfparser:2.11'],
            null,
            null,
        );
    }

    /** @return array<string, mixed> */
    private function prediction(DrawingAnalysisResultData $analysis): array
    {
        $rooms = $this->elementIds($analysis, ['room', 'room_label'], 'room');
        $walls = $this->elementIds($analysis, ['wall'], 'wall');
        $openings = $this->elementIds($analysis, ['opening'], 'opening');
        $areas = [];
        $quantities = [];
        $workIds = [];
        $evidence = [];
        foreach ($analysis->takeoffs as $index => $takeoff) {
            if (! is_array($takeoff)) {
                continue;
            }
            $id = $this->safeId('takeoff', (string) ($takeoff['scope_key'] ?? $index), (string) ($takeoff['name'] ?? ''));
            $amount = $this->decimal($takeoff['quantity'] ?? null);
            if ($amount === null) {
                continue;
            }
            $quantities[$id] = $amount;
            $workIds[] = $id;
            if (($takeoff['scope_key'] ?? null) === 'room_area') {
                $roomId = $rooms[$index % max(1, count($rooms))] ?? $this->safeId('room', (string) $index);
                $areas[$roomId] = $amount;
                if (! in_array($roomId, $rooms, true)) {
                    $rooms[] = $roomId;
                }
            }
            $sourceRefs = is_array($takeoff['source_refs'] ?? null) ? $takeoff['source_refs'] : [];
            if ($sourceRefs !== []) {
                $evidence[$id] = [$this->safeId('evidence', (string) $index, json_encode($sourceRefs) ?: '')];
            }
        }
        $workIds = array_values(array_unique($workIds));
        $applicable = $workIds;
        $profile = is_array($analysis->summary['document_profile'] ?? null) ? $analysis->summary['document_profile'] : [];
        $sheetType = $this->safeToken((string) ($profile['document_role'] ?? 'technical_document'));

        return [
            'sheet_type' => $sheetType,
            'room_cells' => array_values(array_unique($rooms)),
            'wall_cells' => array_values(array_unique($walls)),
            'opening_ids' => array_values(array_unique($openings)),
            'areas' => $areas,
            'quantities' => $quantities,
            'work_ids' => $workIds,
            'normative_rankings' => [],
            'costs' => [],
            'applicable_item_ids' => $applicable,
            'evidence_ids_by_item' => array_intersect_key($evidence, array_flip($applicable)),
            'model_schema_version' => 'current-baseline-prediction:v1',
        ];
    }

    /** @param list<string> $types @return list<string> */
    private function elementIds(DrawingAnalysisResultData $analysis, array $types, string $prefix): array
    {
        $ids = [];
        foreach ($analysis->elements as $index => $element) {
            if (is_array($element) && in_array((string) ($element['type'] ?? ''), $types, true)) {
                $ids[] = $this->safeId($prefix, (string) $index, (string) ($element['label'] ?? ''));
            }
        }

        return array_values(array_unique($ids));
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_numeric($value) || (float) $value < 0 || ! is_finite((float) $value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function safeId(string $prefix, string ...$parts): string
    {
        return $prefix.':'.substr(hash('sha256', implode('|', $parts)), 0, 24);
    }

    private function safeToken(string $value): string
    {
        $value = strtolower((string) preg_replace('/[^a-zA-Z0-9._:-]+/', '_', $value));

        return preg_match('/^[a-zA-Z0-9]/', $value) ? substr($value, 0, 128) : 'technical_document';
    }
}
