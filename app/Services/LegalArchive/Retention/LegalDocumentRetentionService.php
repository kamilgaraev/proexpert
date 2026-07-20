<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Retention;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class LegalDocumentRetentionService
{
    /** @return Collection<int, LegalArchiveDocument> */
    public function evaluate(?Carbon $at = null): Collection
    {
        $at ??= now();
        return LegalArchiveDocument::query()->where('legal_hold', false)->whereNotNull('retention_until')->where('retention_until', '<=', $at)
            ->get()->filter(function (LegalArchiveDocument $document) use ($at): bool {
                $metadata = is_array($document->metadata) ? $document->metadata : [];
                if (($metadata['retention_review_candidate_at'] ?? null) !== null) {
                    return false;
                }
                $document->forceFill(['metadata' => [...$metadata, 'retention_review_candidate_at' => $at->toISOString()]])->save();
                return true;
            })->values();
    }
}
