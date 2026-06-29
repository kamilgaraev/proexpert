<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationScopeInference;

final class EstimatorScopeInferenceService
{
    /**
     * @param array<string, mixed> $factsSummary
     * @return array<int, array<string, mixed>>
     */
    public function persistForDocument(
        EstimateGenerationDocument $document,
        array $factsSummary,
        DrawingAnalysisResultData $drawingAnalysis
    ): array {
        $document->scopeInferences()->delete();
        $inferences = $this->inferFromDocumentPayload([
            'id' => $document->id,
            'filename' => $document->filename,
            'facts_summary' => $factsSummary,
            'drawing_understanding' => $drawingAnalysis->summary,
            'drawing_elements' => $drawingAnalysis->elements,
            'quantity_takeoffs' => $drawingAnalysis->takeoffs,
        ]);

        foreach ($inferences as $inference) {
            EstimateGenerationScopeInference::create($this->scopeInferenceAttributes($document, $inference));
        }

        return $inferences;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    public function inferFromAnalysis(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $inferences = is_array($documentContext['scope_inferences'] ?? null) ? $documentContext['scope_inferences'] : [];

        return array_values(array_filter(array_map(
            fn (mixed $inference): ?array => is_array($inference) ? $this->normalizeInference($inference) : null,
            $inferences
        )));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<int, array<string, mixed>>
     */
    public function inferFromDocumentPayload(array $document): array
    {
        $inferences = [];
        $factsSummary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];
        $drawingSummary = is_array($document['drawing_understanding'] ?? null)
            ? $document['drawing_understanding']
            : (is_array($factsSummary['drawing_understanding'] ?? null) ? $factsSummary['drawing_understanding'] : []);
        $takeoffs = is_array($document['quantity_takeoffs'] ?? null)
            ? $document['quantity_takeoffs']
            : (is_array($drawingSummary['takeoffs'] ?? null) ? $drawingSummary['takeoffs'] : []);
        $elements = is_array($document['drawing_elements'] ?? null)
            ? $document['drawing_elements']
            : (is_array($drawingSummary['elements'] ?? null) ? $drawingSummary['elements'] : []);

        foreach ($takeoffs as $takeoff) {
            if (!is_array($takeoff)) {
                continue;
            }

            $inference = $this->inferenceFromTakeoff($takeoff, $document);

            if ($inference !== null) {
                $inferences[] = $inference;
            }
        }

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $inference = $this->inferenceFromElement($element, $document);

            if ($inference !== null) {
                $inferences[] = $inference;
            }
        }

        foreach ($factsSummary['engineering_systems'] ?? [] as $system) {
            if (!is_array($system)) {
                continue;
            }

            $scopeType = $this->engineeringScope((string) ($system['key'] ?? $system['label'] ?? ''));

            if ($scopeType === null) {
                continue;
            }

            $inferences[] = $this->baseInference(
                'document_fact',
                $scopeType,
                $this->scopeTitle($scopeType),
                0.74,
                $document,
                [
                    'fact' => $system,
                    'quantity_key' => $scopeType . '.points',
                ]
            );
        }

        return $this->unique($inferences);
    }

    /**
     * @param array<string, mixed> $takeoff
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function inferenceFromTakeoff(array $takeoff, array $document): ?array
    {
        $takeoffType = (string) ($takeoff['scope_key'] ?? $takeoff['takeoff_type'] ?? $takeoff['quantity_type'] ?? '');

        return match ($takeoffType) {
            'room_area' => $this->baseInference('drawing_takeoff', 'finishing', 'Отделка помещений', 0.82, $document, [
                'quantity_key' => 'finish.floor',
                'quantity_value' => $takeoff['quantity'] ?? $takeoff['value'] ?? null,
                'unit' => $takeoff['unit'] ?? 'м2',
                'takeoff' => $takeoff,
            ]),
            'opening_count' => $this->baseInference('drawing_takeoff', 'openings', 'Окна и двери', 0.78, $document, [
                'quantity_key' => 'openings.doors',
                'quantity_value' => $takeoff['quantity'] ?? $takeoff['value'] ?? null,
                'unit' => $takeoff['unit'] ?? 'шт',
                'takeoff' => $takeoff,
            ]),
            'engineering_route_length' => $this->baseInference('drawing_takeoff', $this->routeScope($takeoff), $this->scopeTitle($this->routeScope($takeoff)), 0.8, $document, [
                'quantity_key' => $this->routeScope($takeoff) . '.pipe',
                'quantity_value' => $takeoff['quantity'] ?? $takeoff['value'] ?? null,
                'unit' => $takeoff['unit'] ?? 'м',
                'takeoff' => $takeoff,
            ]),
            'specification_quantity' => $this->specificationInference($takeoff, $document),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $takeoff
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function specificationInference(array $takeoff, array $document): ?array
    {
        $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
        $quantityKey = (string) ($payload['quantity_key'] ?? $takeoff['quantity_key'] ?? '');

        if ($quantityKey === '') {
            return null;
        }

        $workIntent = is_array($takeoff['work_intent'] ?? null) ? $takeoff['work_intent'] : [];
        $scopeType = (string) ($payload['scope_type'] ?? $workIntent['scope'] ?? $this->scopeFromQuantityKey($quantityKey));
        $source = (string) ($payload['source'] ?? 'specification');

        $inferenceType = $source === 'work_volume_statement' ? 'work_volume_takeoff' : 'specification_takeoff';
        $title = trim((string) ($takeoff['name'] ?? $payload['name'] ?? ''));

        return $this->baseInference($inferenceType, $scopeType, $title !== '' ? $title : $this->scopeTitle($scopeType), 0.84, $document, [
            'quantity_key' => $quantityKey,
            'quantity_value' => $takeoff['quantity'] ?? $takeoff['value'] ?? null,
            'unit' => $takeoff['unit'] ?? $payload['unit'] ?? null,
            'takeoff' => $takeoff,
            'source' => $source !== '' ? $source : 'specification',
        ]);
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function inferenceFromElement(array $element, array $document): ?array
    {
        $elementType = (string) ($element['element_type'] ?? $element['type'] ?? '');

        return match ($elementType) {
            'room' => $this->baseInference('drawing_element', 'finishing', 'Отделка помещений', 0.76, $document, [
                'quantity_key' => 'finish.floor',
                'element' => $element,
            ]),
            'opening' => $this->baseInference('drawing_element', 'openings', 'Окна и двери', 0.74, $document, [
                'quantity_key' => 'openings.doors',
                'element' => $element,
            ]),
            'engineering_route' => $this->baseInference('drawing_element', $this->routeScope($element), $this->scopeTitle($this->routeScope($element)), 0.76, $document, [
                'quantity_key' => $this->routeScope($element) . '.pipe',
                'element' => $element,
            ]),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function baseInference(
        string $type,
        string $scopeType,
        string $title,
        float $confidence,
        array $document,
        array $payload
    ): array {
        $takeoffRefs = is_array($payload['takeoff']['source_refs'] ?? null) ? $payload['takeoff']['source_refs'] : [];
        $sourceRef = is_array($takeoffRefs[0] ?? null)
            ? $takeoffRefs[0]
            : (is_array($payload['element']['source_ref'] ?? null) ? $payload['element']['source_ref'] : []);

        return [
            'inference_type' => $type,
            'scope_type' => $scopeType,
            'title' => $title,
            'confidence' => $confidence,
            'review_required' => $confidence < 0.8 || (bool) ($payload['review_required'] ?? false),
            'source_ref' => [
                ...$sourceRef,
                'document_id' => $document['id'] ?? $sourceRef['document_id'] ?? null,
                'filename' => $document['filename'] ?? $sourceRef['filename'] ?? null,
            ],
            'source_refs' => [[
                ...$sourceRef,
                'document_id' => $document['id'] ?? $sourceRef['document_id'] ?? null,
                'filename' => $document['filename'] ?? $sourceRef['filename'] ?? null,
            ]],
            'normalized_payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $inference
     * @return array<string, mixed>
     */
    private function scopeInferenceAttributes(EstimateGenerationDocument $document, array $inference): array
    {
        $normalized = $this->normalizeInference($inference);
        $payload = is_array($normalized['normalized_payload'] ?? null) ? $normalized['normalized_payload'] : [];
        $scopeType = (string) ($normalized['scope_type'] ?? 'custom');
        $quantityKey = (string) ($payload['quantity_key'] ?? '');

        return [
            'document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'session_id' => $document->session_id,
            'inference_type' => (string) ($normalized['inference_type'] ?? 'document_fact'),
            'title' => (string) ($normalized['title'] ?? $this->scopeTitle($scopeType)),
            'description' => null,
            'confidence' => (float) ($normalized['confidence'] ?? 0.5),
            'review_required' => (bool) ($normalized['review_required'] ?? true),
            'source_refs' => $normalized['source_refs'],
            'work_intent' => [
                'scope_type' => $scopeType,
                'scope' => $scopeType,
                'quantity_key' => $quantityKey !== '' ? $quantityKey : null,
                'quantity_value' => $payload['quantity_value'] ?? null,
                'unit' => $payload['unit'] ?? null,
                'source' => $payload['source'] ?? null,
            ],
            'normative_basis' => [
                'quantity_key' => $quantityKey !== '' ? $quantityKey : null,
                'quantity_value' => $payload['quantity_value'] ?? null,
                'unit' => $payload['unit'] ?? null,
                'source' => $payload['source'] ?? null,
                'payload' => $payload,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $inference
     * @return array<string, mixed>
     */
    private function normalizeInference(array $inference): array
    {
        $workIntent = is_array($inference['work_intent'] ?? null) ? $inference['work_intent'] : [];
        $normativeBasis = is_array($inference['normative_basis'] ?? null) ? $inference['normative_basis'] : [];
        $payload = is_array($inference['normalized_payload'] ?? null)
            ? $inference['normalized_payload']
            : (is_array($normativeBasis['payload'] ?? null) ? $normativeBasis['payload'] : $normativeBasis);
        $sourceRefs = is_array($inference['source_refs'] ?? null) ? array_values(array_filter($inference['source_refs'], 'is_array')) : [];
        $sourceRef = is_array($inference['source_ref'] ?? null)
            ? $inference['source_ref']
            : (is_array($sourceRefs[0] ?? null) ? $sourceRefs[0] : []);
        $scopeType = (string) (
            $inference['scope_type']
            ?? $workIntent['scope_type']
            ?? $workIntent['scope']
            ?? $payload['scope_type']
            ?? ''
        );

        if ($scopeType === '') {
            $scopeType = $this->scopeFromQuantityKey((string) ($payload['quantity_key'] ?? $workIntent['quantity_key'] ?? ''));
        }

        foreach (['quantity_key', 'quantity_value', 'unit', 'source'] as $field) {
            if (!array_key_exists($field, $payload) && array_key_exists($field, $workIntent)) {
                $payload[$field] = $workIntent[$field];
            }

            if (!array_key_exists($field, $payload) && array_key_exists($field, $normativeBasis)) {
                $payload[$field] = $normativeBasis[$field];
            }
        }

        if ($scopeType !== '' && !array_key_exists('scope_type', $payload)) {
            $payload['scope_type'] = $scopeType;
        }

        if ($sourceRefs === [] && $sourceRef !== []) {
            $sourceRefs = [$sourceRef];
        }

        return [
            'inference_type' => (string) ($inference['inference_type'] ?? 'document_fact'),
            'scope_type' => $scopeType,
            'title' => (string) ($inference['title'] ?? $this->scopeTitle($scopeType)),
            'confidence' => (float) ($inference['confidence'] ?? 0.5),
            'review_required' => (bool) ($inference['review_required'] ?? true),
            'source_ref' => $sourceRef,
            'source_refs' => $sourceRefs,
            'normalized_payload' => $payload,
        ];
    }

    private function routeScope(array $payload): string
    {
        $text = mb_strtolower((string) ($payload['label'] ?? $payload['name'] ?? $payload['source_text'] ?? ''));

        return match (true) {
            str_contains($text, 'вент') || str_contains($text, 'в ') => 'ventilation',
            str_contains($text, 'каб') || str_contains($text, 'эл') => 'electrical',
            str_contains($text, 'отоп') || str_contains($text, 'т ') => 'heating',
            default => 'plumbing',
        };
    }

    private function engineeringScope(string $value): ?string
    {
        $value = mb_strtolower($value);

        return match (true) {
            str_contains($value, 'elect') || str_contains($value, 'элект') => 'electrical',
            str_contains($value, 'vent') || str_contains($value, 'вент') => 'ventilation',
            str_contains($value, 'heat') || str_contains($value, 'отоп') => 'heating',
            str_contains($value, 'water') || str_contains($value, 'plumb') || str_contains($value, 'вод') => 'plumbing',
            default => null,
        };
    }

    private function scopeFromQuantityKey(string $quantityKey): string
    {
        return match (true) {
            str_starts_with($quantityKey, 'earth.') => 'earthworks',
            str_starts_with($quantityKey, 'foundation.') => 'foundation',
            str_starts_with($quantityKey, 'walls.') => 'walls',
            str_starts_with($quantityKey, 'roof.') => 'roof',
            str_starts_with($quantityKey, 'site.'), str_starts_with($quantityKey, 'siteworks.'), str_starts_with($quantityKey, 'networks.') => 'site',
            str_starts_with($quantityKey, 'electrical.'), $quantityKey === 'warehouse.lighting' => 'electrical',
            str_starts_with($quantityKey, 'heating.') => 'heating',
            str_starts_with($quantityKey, 'ventilation.') => 'ventilation',
            str_starts_with($quantityKey, 'openings.'), $quantityKey === 'warehouse.gates' => 'openings',
            str_starts_with($quantityKey, 'sewerage.') => 'plumbing',
            str_starts_with($quantityKey, 'plumbing.'), str_starts_with($quantityKey, 'sanitary.') => 'plumbing',
            str_starts_with($quantityKey, 'rough.'), str_starts_with($quantityKey, 'finish.'), str_starts_with($quantityKey, 'office.') => 'finishing',
            default => 'engineering',
        };
    }

    private function scopeTitle(string $scopeType): string
    {
        return match ($scopeType) {
            'electrical' => 'Электроснабжение',
            'ventilation' => 'Вентиляция',
            'heating' => 'Отопление',
            'plumbing' => 'Водоснабжение и канализация',
            'openings' => 'Окна и двери',
            'earthworks' => 'Земляные работы',
            'finishing' => 'Отделочные работы',
            'foundation' => 'Фундамент',
            'walls' => 'Стены и перегородки',
            'roof' => 'Кровля',
            'site' => 'Подготовка площадки',
            default => 'Строительные работы',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $inferences
     * @return array<int, array<string, mixed>>
     */
    private function unique(array $inferences): array
    {
        $unique = [];

        foreach ($inferences as $inference) {
            $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];
            $key = implode('|', [
                $inference['scope_type'] ?? '',
                $payload['quantity_key'] ?? '',
                $payload['quantity_value'] ?? '',
                $payload['unit'] ?? '',
            ]);

            $unique[$key] = $inference;
        }

        return array_values($unique);
    }
}
