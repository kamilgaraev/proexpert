<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

final class EstimateClarificationAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof EstimateClarificationAnswer) {
            throw new RuntimeException('estimate_generation.clarification_answer_resource_invalid');
        }

        return $this->resource->toArray();
    }
}
