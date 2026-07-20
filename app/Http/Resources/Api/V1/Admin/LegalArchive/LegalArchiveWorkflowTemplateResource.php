<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveWorkflowTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'version' => (int) $this->version,
            'definition_hash' => (string) $this->definition_hash,
            'steps' => $this->steps->map(static fn ($step): array => [
                'key' => (string) $step->step_key,
                'label' => (string) $step->label,
                'sequence' => (int) $step->sequence,
                'parallel_group' => (string) $step->parallel_group,
                'actor_type' => (string) $step->actor_type,
                'actor_reference' => (string) $step->actor_reference,
                'required' => (bool) $step->required,
                'due_in_hours' => $step->due_in_hours,
                'policy_key' => $step->policy_key,
                'settings' => (array) $step->settings,
            ])->values()->all(),
        ];
    }
}
