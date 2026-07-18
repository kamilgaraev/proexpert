<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface GenerationPipelineDataGateway
{
    /** @return array{base_input_version: string, documents: list<array{id: int, source_version: string}>, documents_count: int, rebuild_section_key: string|null} */
    public function manifest(PipelineContext $context): array;

    /** @return array{input: array<string, mixed>, documents: list<array<string, mixed>>, user_id: int|null, normalized_building_model?: array<string, mixed>|null, document_total_area?: array{amount: string, evidence_id: int, confidence: float, floor_count: int}|null} */
    public function source(PipelineContext $context): array;
}
