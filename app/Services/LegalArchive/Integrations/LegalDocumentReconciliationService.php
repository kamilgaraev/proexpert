<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Integrations;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Services\Contract\ContractDossierDocumentCreator;
use Illuminate\Database\Eloquent\Builder;

final class LegalDocumentReconciliationService
{
    public function __construct(private readonly ContractDossierDocumentCreator $documents) {}
    /** @var list<string> */
    public const SOURCES = ['contracts', 'supplementary_agreements', 'acts', 'commercial_proposals', 'procurement', 'payments', 'executive_documentation'];

    /** @return array{candidates:int, linked:int, problem_flags:int, skipped:int, sources:array<string, int>} */
    public function reconcile(?int $organizationId, ?string $source, int $limit, bool $dryRun): array
    {
        $sources = $source === null ? self::SOURCES : [$this->validatedSource($source)];
        $summary = ['candidates' => 0, 'linked' => 0, 'problem_flags' => 0, 'skipped' => 0, 'sources' => array_fill_keys($sources, 0)];

        if (in_array('contracts', $sources, true)) {
            $this->contracts($organizationId)->orderBy('id')->limit($limit)->each(function (Contract $contract) use (&$summary, $dryRun): void {
                $summary['candidates']++;
                $summary['sources']['contracts']++;
                $document = LegalArchiveDocument::query()->where('organization_id', $contract->organization_id)
                    ->where('source_type', 'contract')->where('source_id', (string) $contract->id)->first();
                if (! $document instanceof LegalArchiveDocument) {
                    $summary['problem_flags']++;
                    if (! $dryRun) {
                        $document = $this->documents->create((int) $contract->organization_id, null, [
                            'primary_project_id' => $contract->project_id,
                            'title' => (string) ($contract->subject ?: $contract->number),
                            'document_number' => $contract->number,
                            'document_type' => 'contract',
                            'source_type' => 'contract',
                            'source_id' => (string) $contract->id,
                            'source_idempotency_key' => 'reconcile-contract-'.$contract->id,
                            'links' => [['link_type' => 'contract', 'linked_type' => 'contract', 'linked_id' => (string) $contract->id, 'display_name' => $contract->number]],
                            'metadata' => ['problem_flags' => ['missing_original']],
                        ]);
                        $contract->forceFill(['legal_archive_document_id' => $document->id])->save();
                        $summary['linked']++;
                    }
                    return;
                }
                if ((int) $contract->legal_archive_document_id !== (int) $document->id && ! $dryRun) {
                    $contract->forceFill(['legal_archive_document_id' => $document->id])->save();
                    $summary['linked']++;
                } else {
                    $summary['skipped']++;
                }
            });
        }

        foreach (array_diff($sources, ['contracts']) as $namedSource) {
            $sourceType = [
                'supplementary_agreements' => 'supplementary_agreement', 'acts' => 'act',
                'commercial_proposals' => 'commercial_proposal', 'procurement' => 'procurement',
                'payments' => 'payment', 'executive_documentation' => 'executive_documentation',
            ][$namedSource];
            $count = LegalArchiveDocument::query()->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
                ->where('source_type', $sourceType)->limit($limit)->count();
            $summary['candidates'] += $count;
            $summary['sources'][$namedSource] += $count;
            $summary['skipped'] += $count;
        }

        return $summary;
    }

    private function contracts(?int $organizationId): Builder
    {
        return Contract::query()->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId));
    }

    private function validatedSource(string $source): string
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown reconciliation source.');
        }
        return $source;
    }
}
