<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Obligations;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentObligation;
use Illuminate\Support\Collection;

final class LegalDocumentObligationService
{
    /** @return Collection<int, LegalDocumentObligation> */
    public function syncFromEffectiveDocument(LegalArchiveDocument $document): Collection
    {
        if (! in_array((string) $document->status, ['active', 'effective'], true)) {
            return collect();
        }
        $definitions = data_get($document->structured_fields, 'obligations', []);
        if (! is_array($definitions)) {
            return collect();
        }
        $result = collect();
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['title'] ?? null) || trim($definition['title']) === '') {
                continue;
            }
            $result->push(LegalDocumentObligation::query()->updateOrCreate([
                'document_id' => (int) $document->id,
                'title' => trim($definition['title']),
            ], [
                'organization_id' => (int) $document->organization_id,
                'document_version_id' => $document->current_primary_version_id,
                'project_id' => $document->primary_project_id,
                'responsible_user_id' => $definition['responsible_user_id'] ?? null,
                'responsible_party' => $definition['responsible_party'] ?? null,
                'due_at' => $definition['due_at'] ?? null,
                'amount' => $definition['amount'] ?? null,
                'volume' => $definition['volume'] ?? null,
                'unit' => $definition['unit'] ?? null,
                'status' => $definition['status'] ?? 'open',
                'evidence' => $definition['evidence'] ?? null,
                'metadata' => $definition['metadata'] ?? null,
            ]));
        }
        return $result;
    }
}
