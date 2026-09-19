<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Obligations;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentObligation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\LegalArchive\CanonicalJson;
use Brick\Math\BigDecimal;

final class LegalDocumentObligationService
{
    /** @return Collection<int, LegalDocumentObligation> */
    public function syncFromEffectiveDocument(LegalArchiveDocument $document): Collection
    {
        return DB::transaction(function () use ($document): Collection {
            $locked = LegalArchiveDocument::query()
                ->whereKey($document->id)
                ->where('organization_id', $document->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->syncDefinitions($locked);
        });
    }

    private function syncDefinitions(LegalArchiveDocument $document): Collection
    {
        if (! in_array((string) $document->status, ['active', 'effective'], true)) {
            return collect();
        }
        $definitions = data_get($document->structured_fields, 'obligations', []);
        if (! is_array($definitions)) {
            return collect();
        }
        $result = collect();
        $titleCounts = collect($definitions)->filter(fn ($definition) => is_array($definition) && is_string($definition['title'] ?? null))
            ->countBy(fn (array $definition): string => trim($definition['title']));
        $legacy = LegalDocumentObligation::query()->where('document_id', $document->id)
            ->where('document_version_id', $document->current_primary_version_id)
            ->whereNull('source_key')->get()->keyBy('title');
        $occurrences = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['title'] ?? null) || trim($definition['title']) === '') {
                continue;
            }
            if (isset($definition['id']) && (!is_string($definition['id']) && !is_int($definition['id']))) {
                throw new \DomainException(trans_message('contracts.obligation_identifier_duplicate'));
            }
            $identity = isset($definition['id']) ? 'id:'.(string) $definition['id'] : 'content:'.CanonicalJson::fingerprint($definition);
            $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
            if (isset($definition['id']) && $occurrences[$identity] > 1) {
                throw new \DomainException(trans_message('contracts.obligation_identifier_duplicate'));
            }
            $sourceKey = hash('sha256', json_encode([
                $document->current_primary_version_id,
                $identity,
                $occurrences[$identity],
            ], JSON_THROW_ON_ERROR));
            $existing = $legacy->get(trim($definition['title']));
            if ($existing instanceof LegalDocumentObligation) {
                $this->adoptLegacy($existing, $definition, (int) $titleCounts->get(trim($definition['title'])), $sourceKey);
            }
            $result->push(LegalDocumentObligation::query()->firstOrCreate([
                'document_id' => (int) $document->id,
                'source_key' => $sourceKey,
            ], [
                'title' => trim($definition['title']),
                'organization_id' => (int) $document->organization_id,
                'document_version_id' => $document->current_primary_version_id,
                'project_id' => $document->primary_project_id,
                'responsible_party' => $definition['responsible_party'] ?? null,
                'due_at' => $definition['due_at'] ?? null,
                'amount' => $definition['amount'] ?? null,
                'volume' => $definition['volume'] ?? null,
                'unit' => $definition['unit'] ?? null,
                'status' => $definition['status'] ?? 'open',
            ]));
        }
        return $result;
    }

    private function adoptLegacy(LegalDocumentObligation $obligation, array $definition, int $titleCount, string $sourceKey): void
    {
        $matches = $titleCount === 1;
        foreach (['amount', 'volume'] as $field) {
            $stored = $obligation->getAttribute($field);
            $incoming = $definition[$field] ?? null;
            $matches = $matches && (($stored === null && $incoming === null)
                || ($stored !== null && $incoming !== null && BigDecimal::of((string) $stored)->isEqualTo((string) $incoming)));
        }
        foreach (['unit', 'responsible_party'] as $field) {
            $matches = $matches && $obligation->getAttribute($field) === ($definition[$field] ?? null);
        }
        $incomingDate = empty($definition['due_at']) ? null : \Illuminate\Support\Carbon::parse($definition['due_at'])->toDateTimeString();
        $matches = $matches && $obligation->due_at?->toDateTimeString() === $incomingDate;
        if (!$matches) {
            throw new \DomainException(trans_message('contracts.obligation_legacy_review_required'));
        }
        $obligation->update(['source_key' => $sourceKey]);
    }
}
