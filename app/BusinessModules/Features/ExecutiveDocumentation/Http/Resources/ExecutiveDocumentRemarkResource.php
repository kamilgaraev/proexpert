<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExecutiveDocumentRemark */
final class ExecutiveDocumentRemarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExecutiveDocumentRemark $remark */
        $remark = $this->resource;

        return [
            'id' => $remark->id,
            'document_id' => $remark->document_id,
            'version_id' => $remark->version_id,
            'revision' => count($remark->metadata['review_history'] ?? []),
            'answered_by' => $remark->answered_by,
            'reviewed_by' => $remark->reviewed_by,
            'response_version_id' => $remark->metadata['response_version_id'] ?? null,
            'review_history' => $remark->metadata['review_history'] ?? [],
            'body' => $remark->body,
            'severity' => $remark->severity,
            'status' => $remark->status->value,
            'resolution_comment' => $remark->resolution_comment,
            'response' => $remark->response,
            'review_comment' => $remark->review_comment,
            'answered_at' => $remark->answered_at?->toIso8601String(),
            'reviewed_at' => $remark->reviewed_at?->toIso8601String(),
            'resolved_at' => $remark->resolved_at?->toIso8601String(),
            'created_at' => $remark->created_at?->toIso8601String(),
        ];
    }
}
