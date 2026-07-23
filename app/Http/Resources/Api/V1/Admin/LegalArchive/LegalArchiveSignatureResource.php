<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveSignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_id' => (int) $this->signature_request_id,
            'document_id' => (int) $this->document_id,
            'document_version_id' => (int) $this->document_version_id,
            'method' => (string) $this->method,
            'provider' => $this->provider,
            'signature_kind' => $this->signature_kind,
            'container_format' => $this->container_format,
            'signers' => collect((array) $this->signers)->map(static fn (mixed $signer): array => is_array($signer) ? [
                'kind' => $signer['kind'] ?? null,
                'name' => $signer['name'] ?? null,
                'party_role' => $signer['party_role'] ?? null,
                'position' => $signer['position'] ?? null,
            ] : [])->all(),
            'signed_content_hash' => (string) $this->signed_content_hash,
            'verification_status' => (string) $this->verification_status,
            'diagnostic_code' => $this->diagnostic_code,
            'signed_at' => $this->signed_at?->toAtomString(),
            'verified_at' => $this->verified_at?->toAtomString(),
        ];
    }
}
