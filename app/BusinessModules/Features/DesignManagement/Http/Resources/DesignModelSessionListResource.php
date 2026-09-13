<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignModelSessionListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignModelSession $session */
        $session = $this->resource;
        $revision = $session->modelSetRevision;

        return [
            'id' => $session->id,
            'project_id' => $session->project_id,
            'model_set_id' => $session->model_set_id,
            'model_set_revision' => $revision?->revision,
            'title' => $session->title,
            'models' => $revision?->version_ids ?? [],
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }
}
