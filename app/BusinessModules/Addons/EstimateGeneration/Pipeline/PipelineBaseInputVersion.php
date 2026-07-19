<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class PipelineBaseInputVersion
{
    public const SCHEMA_VERSION = 4;

    /**
     * @param  list<array{id: int, source_version: string, status: string, derived_version: string}>  $documents
     * @param  array{evidence_id?: int, source_version?: string, fingerprint?: string, invalidation_version?: int, active?: bool}|null  $documentTotalArea
     */
    public static function fromProjection(array $input, array $documents, ?array $documentTotalArea = null): string
    {
        unset($input['generation_attempt_id'], $input['generation_requested']);

        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'input' => $input,
            'documents' => $documents,
            'document_total_area_evidence' => self::documentTotalAreaEvidence($documentTotalArea),
        ]));
    }

    /**
     * @param  array{evidence_id?: int, source_version?: string, fingerprint?: string, invalidation_version?: int, active?: bool}|null  $area
     * @return array{evidence_id: int, source_version: string, fingerprint: string, invalidation_version: int, active: true}|null
     */
    private static function documentTotalAreaEvidence(?array $area): ?array
    {
        $evidenceId = (int) ($area['evidence_id'] ?? 0);
        $invalidationVersion = (int) ($area['invalidation_version'] ?? -1);
        if ($evidenceId < 1
            || $invalidationVersion < 0
            || ! is_string($area['source_version'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $area['source_version']) !== 1
            || ! is_string($area['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $area['fingerprint']) !== 1
            || ($area['active'] ?? null) !== true) {
            return null;
        }

        return [
            'evidence_id' => $evidenceId,
            'source_version' => $area['source_version'],
            'fingerprint' => $area['fingerprint'],
            'invalidation_version' => $invalidationVersion,
            'active' => true,
        ];
    }

    /**
     * @param  array{evidence_id?: int, source_version?: string, fingerprint?: string, invalidation_version?: int, active?: bool}|null  $documentTotalArea
     */
    public static function fromSession(EstimateGenerationSession $session, ?array $documentTotalArea = null): string
    {
        $input = is_array($session->input_payload) ? $session->input_payload : [];
        unset($input['generation_attempt_id'], $input['generation_requested']);
        $documents = $session->documents
            ->map(static fn ($document): array => [
                'id' => (int) $document->getKey(),
                'source_version' => DocumentSourceVersion::fromDocument($document),
                'status' => (string) $document->status,
                'derived_version' => self::derivedVersion($document),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return self::fromProjection($input, $documents, $documentTotalArea);
    }

    private static function derivedVersion(object $document): string
    {
        $relations = [];
        foreach (['facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'] as $relation) {
            if (! $document->relationLoaded($relation)) {
                throw new \LogicException('Pipeline base input requires all consumed document relations.');
            }
            $relations[$relation] = $document->{$relation}
                ->sortBy('id')
                ->map(static fn ($model): array => [
                    'id' => (int) $model->getKey(),
                    'version' => self::digest($model->toArray()),
                ])
                ->values()
                ->all();
        }

        return self::digest([
            'structured_payload' => self::digest(is_array($document->structured_payload) ? $document->structured_payload : []),
            'facts_summary' => self::digest(is_array($document->facts_summary) ? $document->facts_summary : []),
            'quality' => self::digest([
                'score' => $document->quality_score,
                'level' => $document->quality_level,
                'flags' => is_array($document->quality_flags) ? $document->quality_flags : [],
            ]),
            'relations' => $relations,
        ]);
    }

    private static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($value));
    }
}
