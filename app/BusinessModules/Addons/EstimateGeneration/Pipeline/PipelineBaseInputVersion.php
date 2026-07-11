<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class PipelineBaseInputVersion
{
    public static function fromSession(EstimateGenerationSession $session): string
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

        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode([
            'input' => $input,
            'documents' => $documents,
        ]));
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
