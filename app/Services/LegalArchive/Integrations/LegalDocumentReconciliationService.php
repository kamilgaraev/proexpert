<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Integrations;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\ContractDossierDocumentCreator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LegalDocumentReconciliationService
{
    /** @var list<string> */
    public const SOURCES = [
        'contracts',
        'supplementary_agreements',
        'acts',
        'commercial_proposals',
        'procurement',
        'payments',
        'executive_documentation',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ContractDossierDocumentCreator $documents,
    ) {}

    /** @return array{candidates:int, linked:int, problem_flags:int, skipped:int, sources:array<string, int>} */
    public function reconcile(?int $organizationId, ?string $source, int $limit, bool $dryRun): array
    {
        $sources = $source === null ? self::SOURCES : [$this->validatedSource($source)];
        $summary = [
            'candidates' => 0,
            'linked' => 0,
            'problem_flags' => 0,
            'skipped' => 0,
            'sources' => array_fill_keys($sources, 0),
        ];

        foreach ($sources as $namedSource) {
            $remaining = $limit - $summary['candidates'];
            if ($remaining < 1) {
                break;
            }

            if ($namedSource === 'contracts') {
                $this->repairMissingContractDossiers($organizationId, $remaining, $dryRun, $summary);
                $remaining = $limit - $summary['candidates'];
                if ($remaining < 1) {
                    continue;
                }

                $this->repairContractLinks($organizationId, $remaining, $dryRun, $summary);
                $remaining = $limit - $summary['candidates'];
                if ($remaining < 1) {
                    continue;
                }
            }

            $sourceType = $this->sourceType($namedSource);
            $sourceQuery = $this->sourceQuery($namedSource, $organizationId);
            $cursor = $dryRun ? 0 : $this->cursor($organizationId, $namedSource);
            $lastSourceId = $cursor;
            $cursorOrganizationId = $organizationId;
            $this->unreconciled($sourceQuery, $namedSource, $sourceType)
                ->where($sourceQuery->getModel()->qualifyColumn('id'), '>', $cursor)
                ->orderBy('id')
                ->chunkById(100, function (Collection $entities) use ($namedSource, $sourceType, $dryRun, $cursorOrganizationId, &$lastSourceId, &$summary, $limit): bool {
                    $documents = LegalArchiveDocument::query()
                        ->withTrashed()
                        ->where('source_type', $sourceType)
                        ->whereIn('source_id', $entities->modelKeys())
                        ->get()
                        ->keyBy(static fn (LegalArchiveDocument $document): string => "{$document->organization_id}:{$document->source_id}");

                    foreach ($entities as $entity) {
                        $lastSourceId = (int) $entity->getKey();
                        $organizationId = $this->organizationId($entity);
                        $document = $documents->get("{$organizationId}:{$entity->getKey()}");
                        if ($document instanceof LegalArchiveDocument && $document->trashed()) {
                            $summary['problem_flags']++;
                            $summary['skipped']++;

                            continue;
                        }
                        $needsLinkRepair = $document instanceof LegalArchiveDocument
                            && $entity instanceof Contract
                            && (int) $entity->legal_archive_document_id !== (int) ($document?->id ?? 0);
                        if (! $document instanceof LegalArchiveDocument && ! $needsLinkRepair) {
                            $summary['candidates']++;
                            $summary['sources'][$namedSource]++;
                            $summary['problem_flags']++;
                            if (! $dryRun) {
                                if ($entity instanceof Contract) {
                                    $this->createAndLinkContract($entity, $organizationId, $sourceType, $summary);
                                } else {
                                    $document = $this->documents->create($organizationId, null, $this->createPayload($entity, $sourceType));
                                    $summary['linked']++;
                                }
                            }
                        } elseif ($needsLinkRepair) {
                            $summary['candidates']++;
                            $summary['sources'][$namedSource]++;
                            if ($entity instanceof Contract && ! $dryRun) {
                                $this->createAndLinkContract($entity, $organizationId, $sourceType, $summary);
                            } else {
                                $summary['skipped']++;
                            }
                        } else {
                            $summary['skipped']++;
                        }

                        if ($summary['candidates'] >= $limit) {
                            $this->advanceCursor($cursorOrganizationId, $namedSource, $lastSourceId, $dryRun);

                            return false;
                        }
                    }

                    $this->advanceCursor($cursorOrganizationId, $namedSource, $lastSourceId, $dryRun);

                    return true;
                });
        }

        return $summary;
    }

    private function cursor(?int $organizationId, string $source): int
    {
        return (int) DB::table('legal_document_reconciliation_cursors')
            ->where('organization_id', $organizationId ?? 0)
            ->where('source', $source)
            ->value('last_source_id');
    }

    private function advanceCursor(?int $organizationId, string $source, int $lastSourceId, bool $dryRun): void
    {
        if ($dryRun || $lastSourceId < 1) {
            return;
        }

        $identity = [
            'organization_id' => $organizationId ?? 0,
            'source' => $source,
        ];
        $now = now();

        DB::table('legal_document_reconciliation_cursors')->insertOrIgnore([
            ...$identity,
            'last_source_id' => $lastSourceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('legal_document_reconciliation_cursors')
            ->where($identity)
            ->where('last_source_id', '<', $lastSourceId)
            ->update([
                'last_source_id' => $lastSourceId,
                'updated_at' => $now,
            ]);
    }

    /** @return array<string, mixed> */
    private function createPayload(Model $entity, string $sourceType): array
    {
        return [
            'primary_project_id' => $this->projectId($entity),
            'title' => $this->title($entity, $sourceType),
            'document_number' => $this->documentNumber($entity),
            'document_type' => $sourceType,
            'source_type' => $sourceType,
            'source_id' => (string) $entity->getKey(),
            'source_idempotency_key' => "reconcile-{$sourceType}-{$entity->getKey()}",
            'links' => [[
                'link_type' => $sourceType,
                'linked_type' => $sourceType,
                'linked_id' => (string) $entity->getKey(),
                'display_name' => $this->documentNumber($entity) ?? $this->title($entity, $sourceType),
            ]],
            'metadata' => ['problem_flags' => ['missing_original']],
        ];
    }

    /** @param array{linked:int, skipped:int} $summary */
    private function createAndLinkContract(
        Contract $contract,
        int $organizationId,
        string $sourceType,
        array &$summary,
    ): void {
        $this->connection->transaction(function () use ($contract, $organizationId, $sourceType, &$summary): void {
            $lockedContract = Contract::query()
                ->where('organization_id', $organizationId)
                ->whereKey((int) $contract->getKey())
                ->lockForUpdate()
                ->first();
            if (! $lockedContract instanceof Contract) {
                $summary['skipped']++;

                return;
            }
            if ((int) $lockedContract->legal_archive_document_id > 0) {
                $summary['skipped']++;

                return;
            }

            $document = LegalArchiveDocument::query()
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('source_type', $sourceType)
                ->where('source_id', (string) $lockedContract->getKey())
                ->lockForUpdate()
                ->first();
            if ($document instanceof LegalArchiveDocument && $document->trashed()) {
                $summary['skipped']++;

                return;
            }
            if (! $document instanceof LegalArchiveDocument) {
                $document = $this->documents->create(
                    $organizationId,
                    null,
                    $this->createPayload($lockedContract, $sourceType),
                );
            }

            $this->linkContract($lockedContract, $document, false, $summary);
        });
    }

    private function sourceQuery(string $source, ?int $organizationId): Builder
    {
        return match ($source) {
            'contracts' => Contract::query()
                ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            'supplementary_agreements' => SupplementaryAgreement::query()
                ->with('contract:id,organization_id,project_id')
                ->whereHas('contract', static fn (Builder $query): Builder => $query->when($organizationId !== null, static fn (Builder $contract): Builder => $contract->where('organization_id', $organizationId))),
            'acts' => ContractPerformanceAct::query()
                ->with('contract:id,organization_id,project_id')
                ->whereHas('contract', static fn (Builder $query): Builder => $query->when($organizationId !== null, static fn (Builder $contract): Builder => $contract->where('organization_id', $organizationId))),
            'commercial_proposals' => CommercialProposal::query()
                ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            'procurement' => PurchaseOrder::query()
                ->with('contract:id,project_id')
                ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            'payments' => PaymentDocument::query()
                ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            'executive_documentation' => ExecutiveDocument::query()
                ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            default => throw new InvalidArgumentException('Unknown reconciliation source.'),
        };
    }

    /** @param array{candidates:int, linked:int, problem_flags:int, skipped:int, sources:array<string, int>} $summary */
    private function repairMissingContractDossiers(?int $organizationId, int $limit, bool $dryRun, array &$summary): void
    {
        Contract::query()
            ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('contracts.organization_id', $organizationId))
            ->whereNull('contracts.legal_archive_document_id')
            ->whereNotExists(function (QueryBuilder $documents): void {
                $documents->selectRaw('1')
                    ->from('legal_archive_documents as dossier')
                    ->whereColumn('dossier.organization_id', 'contracts.organization_id')
                    ->where('dossier.source_type', 'contract')
                    ->whereRaw('dossier.source_id = CAST(contracts.id AS text)');
            })
            ->orderBy('contracts.id')
            ->limit($limit)
            ->get(['contracts.*'])
            ->each(function (Contract $contract) use ($dryRun, &$summary): void {
                $summary['candidates']++;
                $summary['sources']['contracts']++;
                $summary['problem_flags']++;
                if ($dryRun) {
                    return;
                }

                $this->createAndLinkContract($contract, (int) $contract->organization_id, 'contract', $summary);
            });
    }

    /** @param array{candidates:int, linked:int, problem_flags:int, skipped:int, sources:array<string, int>} $summary */
    private function repairContractLinks(?int $organizationId, int $limit, bool $dryRun, array &$summary): void
    {
        Contract::query()
            ->join('legal_archive_documents as dossier', function ($join): void {
                $join->on('dossier.organization_id', '=', 'contracts.organization_id')
                    ->where('dossier.source_type', '=', 'contract')
                    ->whereNull('dossier.deleted_at')
                    ->whereRaw('dossier.source_id = CAST(contracts.id AS text)');
            })
            ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('contracts.organization_id', $organizationId))
            ->whereNull('contracts.legal_archive_document_id')
            ->orderBy('contracts.id')
            ->limit($limit)
            ->get(['contracts.*'])
            ->each(function (Contract $contract) use ($dryRun, &$summary): void {
                $summary['candidates']++;
                $summary['sources']['contracts']++;
                if ($dryRun) {
                    return;
                }

                $this->createAndLinkContract($contract, (int) $contract->organization_id, 'contract', $summary);
            });
    }

    private function unreconciled(Builder $query, string $source, string $sourceType): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(function (QueryBuilder $documents) use ($source, $sourceType, $table): void {
            $documents->selectRaw('1')
                ->from('legal_archive_documents as dossier')
                ->where('dossier.source_type', $sourceType)
                ->whereNull('dossier.deleted_at')
                ->whereRaw("dossier.source_id = CAST({$table}.id AS text)");

            if (in_array($source, ['supplementary_agreements', 'acts'], true)) {
                $documents->whereRaw("dossier.organization_id = (SELECT organization_id FROM contracts WHERE contracts.id = {$table}.contract_id)");

                return;
            }

            $documents->whereColumn('dossier.organization_id', "{$table}.organization_id");
        });
    }

    private function sourceType(string $source): string
    {
        return match ($source) {
            'contracts' => 'contract',
            'supplementary_agreements' => 'supplementary_agreement',
            'acts' => 'performance_act',
            'commercial_proposals' => 'commercial_proposal',
            'procurement' => 'purchase_order',
            'payments' => 'payment_document',
            'executive_documentation' => 'executive_document',
        };
    }

    private function organizationId(Model $entity): int
    {
        $organizationId = $entity->getAttribute('organization_id')
            ?? $entity->getRelation('contract')?->getAttribute('organization_id');

        if (! is_numeric($organizationId) || (int) $organizationId < 1) {
            throw new InvalidArgumentException('Legal archive source has no organization.');
        }

        return (int) $organizationId;
    }

    private function projectId(Model $entity): ?int
    {
        $projectId = $entity->getAttribute('project_id')
            ?? $entity->getRelation('contract')?->getAttribute('project_id');

        return is_numeric($projectId) && (int) $projectId > 0 ? (int) $projectId : null;
    }

    private function title(Model $entity, string $sourceType): string
    {
        foreach (['title', 'subject', 'description', 'name', 'number', 'order_number', 'document_number', 'act_document_number'] as $attribute) {
            $value = $entity->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return "{$sourceType} #{$entity->getKey()}";
    }

    private function documentNumber(Model $entity): ?string
    {
        foreach (['number', 'order_number', 'document_number', 'act_document_number'] as $attribute) {
            $value = $entity->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array{linked:int, skipped:int} $summary */
    private function linkContract(Model $entity, LegalArchiveDocument $document, bool $dryRun, array &$summary): void
    {
        if (! $entity instanceof Contract || $dryRun) {
            $summary['skipped']++;

            return;
        }

        $boundDocumentId = (int) $entity->legal_archive_document_id;
        if ($boundDocumentId > 0 && $boundDocumentId !== (int) $document->id) {
            $summary['skipped']++;

            return;
        }
        if ($boundDocumentId !== (int) $document->id) {
            $entity->forceFill(['legal_archive_document_id' => $document->id])->save();
            $summary['linked']++;

            return;
        }

        $summary['skipped']++;
    }

    private function validatedSource(string $source): string
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown reconciliation source.');
        }

        return $source;
    }
}
