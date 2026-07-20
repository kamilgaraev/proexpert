<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Integrations;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use App\Models\ContractPerformanceAct;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
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

        $definitions = ['supplementary_agreements' => [SupplementaryAgreement::class, 'supplementary_agreement'], 'acts' => [ContractPerformanceAct::class, 'performance_act'], 'commercial_proposals' => [CommercialProposal::class, 'commercial_proposal'], 'procurement' => [PurchaseOrder::class, 'purchase_order'], 'payments' => [PaymentDocument::class, 'payment_document'], 'executive_documentation' => [ExecutiveDocument::class, 'executive_document']];
        foreach (array_diff($sources, ['contracts']) as $namedSource) {
            if ($summary['candidates'] >= $limit) {
                break;
            }
            [$model, $sourceType] = $definitions[$namedSource];
            $model::query()->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId))->orderBy('id')->limit($limit - $summary['candidates'])->each(function ($entity) use (&$summary, $namedSource, $sourceType, $dryRun): void {
                $summary['candidates']++; $summary['sources'][$namedSource]++;
                $document = LegalArchiveDocument::query()->where('organization_id', $entity->organization_id)->where('source_type', $sourceType)->where('source_id', (string) $entity->id)->first();
                if ($document instanceof LegalArchiveDocument) { $summary['skipped']++; return; }
                $summary['problem_flags']++;
                if (!$dryRun) { $this->documents->create((int) $entity->organization_id, null, ['title' => (string) ($entity->title ?? $entity->name ?? $entity->number ?? ('Документ '.$entity->id)), 'document_number' => $entity->number ?? null, 'document_type' => $sourceType, 'source_type' => $sourceType, 'source_id' => (string) $entity->id, 'source_idempotency_key' => 'reconcile-'.$sourceType.'-'.$entity->id, 'metadata' => ['problem_flags' => ['missing_original']]]); $summary['linked']++; }
            });
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
