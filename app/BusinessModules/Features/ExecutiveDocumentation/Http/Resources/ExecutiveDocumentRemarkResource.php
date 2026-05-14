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
            'body' => $remark->body,
            'severity' => $remark->severity,
            'status' => $remark->status->value,
            'resolution_comment' => $remark->resolution_comment,
            'resolved_at' => $remark->resolved_at?->toIso8601String(),
            'created_at' => $remark->created_at?->toIso8601String(),
        ];
    }
}
