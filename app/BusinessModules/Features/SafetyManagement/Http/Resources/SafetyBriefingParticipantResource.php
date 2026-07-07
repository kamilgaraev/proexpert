<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyBriefingParticipant */
final class SafetyBriefingParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyBriefingParticipant $participant */
        $participant = $this->resource;
        $requestUserId = $request->user()?->id;
        $canSign = $requestUserId !== null
            && $participant->user_id !== null
            && (int) $participant->user_id === (int) $requestUserId
            && $participant->signature_status === 'pending';

        return [
            'id' => $participant->id,
            'employee_id' => $participant->employee_id,
            'user_id' => $participant->user_id,
            'external_name' => $participant->external_name,
            'company_name' => $participant->company_name,
            'role_name' => $participant->role_name,
            'signature_status' => $participant->signature_status,
            'signature_status_label' => trans_message("safety_management.briefing_participant_statuses.{$participant->signature_status}"),
            'signed_at' => $participant->signed_at?->toIso8601String(),
            'signed_by_user_id' => $participant->signed_by_user_id,
            'signature_method' => $participant->signature_method,
            'refusal_reason' => $participant->refusal_reason,
            'absence_reason' => $participant->absence_reason,
            'signature_metadata' => $participant->signature_metadata ?? [],
            'can_sign' => $canSign,
            'metadata' => $participant->metadata,
            'user' => $this->whenLoaded('user', fn () => $participant->user ? [
                'id' => $participant->user->id,
                'name' => $participant->user->name,
            ] : null),
            'employee' => $this->whenLoaded('employee', fn () => $participant->employee ? [
                'id' => $participant->employee->id,
                'full_name' => $participant->employee->full_name,
            ] : null),
            'signed_by_user' => $this->whenLoaded('signedByUser', fn () => $participant->signedByUser ? [
                'id' => $participant->signedByUser->id,
                'name' => $participant->signedByUser->name,
            ] : null),
        ];
    }
}
