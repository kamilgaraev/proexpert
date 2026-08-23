<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;

final class RagTestEmbedding
{
    public const DIMENSIONS = 256;

    /**
     * @param  array<int, float>  $leadingValues
     * @return array<int, float>
     */
    public static function fromLeadingValues(array $leadingValues): array
    {
        if (count($leadingValues) > self::DIMENSIONS) {
            throw new InvalidArgumentException('rag_test_embedding_dimensions_exceeded');
        }

        return array_pad($leadingValues, self::DIMENSIONS, 0.0);
    }
}
