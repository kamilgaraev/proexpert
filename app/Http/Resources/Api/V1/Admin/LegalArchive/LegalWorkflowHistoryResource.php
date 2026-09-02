<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalWorkflowHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $action = in_array($this->action, ['approve', 'reject', 'return', 'reassign', 'cancel', 'expire'], true)
            ? $this->action : 'other';

        return [
            'id' => (int) $this->id,
            'action' => $action,
            'action_label' => trans_message('legal_workflow_history.actions.'.$action),
            'actor_name' => $this->actor_name ?: trans_message($this->actor_type === 'system'
                ? 'legal_workflow_history.system_actor' : 'legal_workflow_history.unavailable_actor'),
            'step_label' => $this->step_label,
            'version_number' => $this->version_number === null ? null : (string) $this->version_number,
            'comment' => $this->comment,
            'reason' => $this->reason,
            'decided_at' => CarbonImmutable::parse($this->decided_at)->toAtomString(),
        ];
    }
}
