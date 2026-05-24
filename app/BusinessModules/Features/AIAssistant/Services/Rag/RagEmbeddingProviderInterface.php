<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

interface RagEmbeddingProviderInterface
{
    public const PURPOSE_DOCUMENT = 'document';

    public const PURPOSE_QUERY = 'query';

    /**
     * @return array<int, float>
     */
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array;

    public function provider(): string;

    public function model(): string;

    public function dimensions(): int;
}
