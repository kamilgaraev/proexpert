<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class EloquentFailureStore implements FailureStore
{
    public function __construct(private Connection $database) {}

    public function record(FailureData $failure, DateTimeImmutable $seenAt): void
    {
        $payload = [
            'id' => self::deterministicId($failure->fingerprint),
            'fingerprint' => $failure->fingerprint,
            'organization_id' => $failure->context->organizationId,
            'project_id' => $failure->context->projectId,
            'session_id' => $failure->context->sessionId,
            'document_id' => $failure->context->documentId,
            'page_id' => $failure->context->pageId,
            'unit_id' => $failure->context->unitId,
            'checkpoint_id' => $failure->context->checkpointId,
            'usage_attempt_id' => $failure->context->usageAttemptId,
            'correlation_id' => strtolower($failure->context->correlationId),
            'stage' => $failure->context->stage->value,
            'operation' => $failure->context->operation,
            'provider' => $failure->context->provider,
            'model' => $failure->context->model,
            'category' => $failure->category->value,
            'code' => $failure->code,
            'attempt' => $failure->context->attempt,
            'safe_context' => $failure->safeContext,
            'seen_at' => $seenAt->format(DATE_ATOM),
        ];

        $result = $this->database->selectOne(
            'SELECT record_estimate_generation_failure(?::jsonb) AS fingerprint',
            [json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        );
        if ($result === null || ! isset($result->fingerprint) || ! hash_equals($failure->fingerprint, (string) $result->fingerprint)) {
            throw new FailureStoreInvariantViolation('Failure record was not persisted with its immutable identity.');
        }
    }

    public function resolve(
        FailureContext $context,
        string $fingerprint,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): bool {
        $result = $this->database->selectOne(
            'SELECT resolve_estimate_generation_failure(?, ?, ?, ?, ?, ?::timestamptz) AS resolved',
            [
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $fingerprint,
                $resolutionCode,
                $resolvedAt->format(DATE_ATOM),
            ],
        );

        return (bool) ($result?->resolved ?? false);
    }

    public function resolveActive(
        FailureContext $context,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): int {
        $result = $this->database->selectOne(
            'SELECT resolve_active_estimate_generation_failures(?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz) AS resolved_count',
            [
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $context->documentId,
                $context->unitId,
                $context->stage->value,
                $context->operation,
                $resolutionCode,
                $resolvedAt->format(DATE_ATOM),
            ],
        );

        return (int) ($result?->resolved_count ?? 0);
    }

    private static function deterministicId(string $fingerprint): string
    {
        $hex = substr(hash('sha256', $fingerprint), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
