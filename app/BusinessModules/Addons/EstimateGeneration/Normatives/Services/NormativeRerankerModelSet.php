<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use InvalidArgumentException;

final readonly class NormativeRerankerModelSet
{
    /** @var non-empty-list<string> */
    public array $models;

    public function __construct(string|array|null $configured = null)
    {
        $values = $configured ?? config('estimate-generation.normative_matching.reranker.models');
        $values = is_string($values) ? explode(',', $values) : $values;
        $models = array_slice(array_values(array_unique(array_filter(array_map(static fn (mixed $model): string => trim((string) $model), is_array($values) ? $values : [])))), 0, 4);
        foreach ($models as $model) {
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\z/i', $model) !== 1) {
                throw new InvalidArgumentException('Normative reranker model ID is invalid.');
            }
        }
        if ($models === []) {
            throw new InvalidArgumentException('Normative reranker model set is empty.');
        }
        $this->models = $models;
    }

    public function version(): string
    {
        return 'models:'.hash('sha256', implode('|', $this->models));
    }
}
