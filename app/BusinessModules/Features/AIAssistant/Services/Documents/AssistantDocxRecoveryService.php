<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\Jobs\RecoverEmptyAssistantDocxText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantDocxRecoveryService
{
    private const MAX_DOCUMENTS_PER_SCAN = 5;

    public function dispatchUnversionedEmptyDocx(int $organizationId): int
    {
        $version = DocumentTextExtractor::DOCX_EXTRACTION_VERSION;

        return DB::transaction(function () use ($organizationId, $version): int {
            $documents = AIAssistantDocument::query()
                ->where('organization_id', $organizationId)
                ->where('status', AIAssistantDocument::STATUS_READY)
                ->where('coverage_status', 'empty')
                ->whereRaw('LOWER(filename) LIKE ?', ['%.docx'])
                ->where(function (Builder $query) use ($version): void {
                    $query->whereNull('metadata->docx_text_extraction_version')
                        ->orWhere('metadata->docx_text_extraction_version', '!=', $version);
                })
                ->where(function (Builder $query) use ($version): void {
                    $query->whereNull('metadata->docx_recovery_pending_version')
                        ->orWhere('metadata->docx_recovery_pending_version', '!=', $version);
                })
                ->orderBy('id')
                ->limit(self::MAX_DOCUMENTS_PER_SCAN)
                ->lockForUpdate()
                ->get();

            foreach ($documents as $document) {
                $metadata = array_merge($document->metadata ?? [], ['docx_recovery_pending_version' => $version]);
                $document->update(['metadata' => $metadata]);
                RecoverEmptyAssistantDocxText::dispatch((int) $document->id, $document->checksum, $version)->afterCommit();
            }

            return $documents->count();
        });
    }
}
