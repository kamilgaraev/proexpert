<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class AiOperationContext
{
    public function __construct(
        public string $correlationId,
        public string $attemptId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $stage,
        public string $operation,
        public int $attemptOrdinal,
        public ?int $documentId = null,
        public ?int $pageId = null,
        public ?int $unitId = null,
    ) {
        foreach ([$correlationId, $attemptId] as $uuid) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
                throw new InvalidArgumentException('Invalid usage UUID.');
            }
        }
        foreach ([$organizationId, $projectId, $sessionId, $attemptOrdinal] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Usage context identifiers must be positive.');
            }
        }
        foreach ([$documentId, $pageId, $unitId] as $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException('Optional usage scope identifiers must be positive.');
            }
        }
        if (! in_array($stage, ['understand_documents', 'match_normatives'], true)
            || ! in_array($operation, ['ocr', 'vision', 'rerank'], true)
            || ($stage === 'match_normatives') !== ($operation === 'rerank')) {
            throw new InvalidArgumentException('Invalid usage operation context.');
        }
    }

    public static function deterministicId(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
