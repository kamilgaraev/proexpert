<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Retention;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Notifications\LegalArchive\LegalDocumentDeadlineNotification;
use App\Services\LegalArchive\LegalDocumentNotificationPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class LegalDocumentRetentionService
{
    public function __construct(private readonly LegalDocumentNotificationPublisher $notifications) {}
    /** @return Collection<int, LegalArchiveDocument> */
    public function evaluate(?Carbon $at = null): Collection
    {
        $at ??= now();
        return LegalArchiveDocument::query()->with('obligations.responsible')->where('legal_hold', false)->whereNotNull('retention_until')->where('retention_until', '<=', $at)
            ->get()->filter(function (LegalArchiveDocument $document) use ($at): bool {
                $metadata = is_array($document->metadata) ? $document->metadata : [];
                if (($metadata['retention_review_candidate_at'] ?? null) !== null) {
                    return false;
                }
                foreach ($document->obligations as $obligation) {
                    if ($obligation->responsible !== null && $obligation->status === 'open' && $obligation->due_at?->isPast()) {
                        $this->notifications->publish($document, $obligation->responsible, 'obligation_overdue:'.$obligation->id.':'.$obligation->due_at?->toDateString(), new LegalDocumentDeadlineNotification($document, 'obligation_overdue'));
                    }
                }
                $document->forceFill(['metadata' => [...$metadata, 'retention_review_candidate_at' => $at->toISOString()]])->save();
                return true;
            })->values();
    }
}
