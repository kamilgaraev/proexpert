<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractLegalDossierCandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'document_number' => $this->document_number,
            'type_profile_code' => (string) $this->type_profile_code,
            'document_date' => $this->document_date?->toDateString(),
            'status' => (string) $this->status,
        ];
    }
}
