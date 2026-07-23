<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalSignatureVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'signature_id' => (int) $this->signature_id,
            'status' => (string) $this->status,
            'verified_at' => $this->verified_at?->toAtomString(),
            'revocation_reason' => $this->revocation_reason,
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
