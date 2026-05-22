<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class ModelJsonResource extends JsonResource
{
    /**
     * @template TResource of object
     *
     * @param class-string<TResource> $class
     * @return TResource
     */
    protected function typedResource(string $class): object
    {
        assert($this->resource instanceof $class);

        return $this->resource;
    }
}
