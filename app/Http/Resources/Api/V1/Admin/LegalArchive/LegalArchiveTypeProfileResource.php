<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveTypeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = is_array($this->resource) ? $this->resource : $this->resource->toArray();

        return [
            'id' => $value['id'] ?? null,
            'organization_id' => $value['organization_id'] ?? null,
            'code' => (string) ($value['code'] ?? ''),
            'base_code' => (string) ($value['base_code'] ?? ''),
            'name' => (string) ($value['name'] ?? ''),
            'category' => $value['category'] ?? null,
            'schema' => (array) ($value['schema'] ?? []),
            'required_fields' => (array) ($value['required_fields'] ?? []),
            'required_file_roles' => (array) ($value['required_file_roles'] ?? []),
            'requires_signature' => $value['requires_signature'] ?? null,
            'allowed_signature_kinds' => $value['allowed_signature_kinds'] ?? null,
            'required_signature_kinds' => $value['required_signature_kinds'] ?? null,
            'allowed_signature_formats' => $value['allowed_signature_formats'] ?? null,
            'workflow_template_id' => $value['workflow_template_id'] ?? null,
            'retention_policy' => $value['retention_policy'] ?? null,
            'confidentiality_level' => $value['confidentiality_level'] ?? null,
            'is_standard' => (bool) ($value['is_standard'] ?? false),
            'is_active' => (bool) ($value['is_active'] ?? true),
            'lock_version' => (int) ($value['lock_version'] ?? 0),
        ];
    }
}
