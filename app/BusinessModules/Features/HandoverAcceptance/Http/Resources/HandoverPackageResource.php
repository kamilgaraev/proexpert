<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HandoverPackage */
final class HandoverPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->resource;

        if (!$package instanceof HandoverPackage) {
            return [];
        }

        return [
            'id' => $package->id,
            'acceptance_scope_id' => $package->acceptance_scope_id,
            'title' => $package->title,
            'status' => $package->status,
            'documents' => $package->relationLoaded('documents') ? $package->documents->map(fn ($document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'document_type' => $document->document_type,
                'is_required' => $document->is_required,
                'status' => $document->status,
                'external_url' => $document->external_url,
                'approved_at' => $document->approved_at?->toIso8601String(),
                'available_actions' => $document->status === 'approved' ? [] : ['upload'],
            ])->values()->all() : [],
        ];
    }
}
