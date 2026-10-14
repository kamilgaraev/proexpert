<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveWorkflowSubmitPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'document_id' => $this->documentId,
            'document_version_id' => $this->documentVersionId,
            'document_lock_version' => $this->documentLockVersion,
            'template' => $this->template,
            'steps' => $this->steps,
        ];
    }
}
