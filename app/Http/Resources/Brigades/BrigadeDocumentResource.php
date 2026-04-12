<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeDocument */
class BrigadeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'document_type' => $this->document_type,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'verification_status' => $this->verification_status,
            'verification_notes' => $this->verification_notes,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
