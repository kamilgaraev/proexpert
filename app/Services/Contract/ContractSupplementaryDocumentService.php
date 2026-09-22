<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractSupplementaryDocumentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractLibraryService $library,
        private readonly ContractSupplementaryDocumentComposer $composer,
    ) {}

    public function list(User $actor, int $organizationId, int $contractId): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $this->assertParty($actor, $organizationId, $contractId);
        $contract = Contract::findOrFail($contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->first();
        $open = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
            ->whereNotIn('status', ['applied', 'cancelled'])->exists();
        $canCreate = false;
        $blocked = null;
        if ($instance === null || $instance->effective_revision_id === null) {
            $blocked = 'not_effective';
        } elseif ($contract->getRawOriginal('status') !== 'active') {
            $blocked = 'not_effective';
        } elseif ($open) {
            $blocked = 'open_document';
        } else {
            $canCreate = $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId]);
        }
        $items = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
            ->orderByDesc('id')->get()
            ->map(fn (object $row): array => $this->present($actor, $organizationId, $row))->all();

        return ['items' => $items, 'can_create' => $canCreate, 'blocked_reason' => $blocked];
    }

    public function create(User $actor, int $organizationId, int $contractId, string $templateId, int $templateVersion, string $number, string $agreementDate, string $key): array
    {
        $this->authorizeEdit($actor, $organizationId);
        if (!Str::isUuid($templateId) || $templateVersion < 1 || trim($number) === '' || mb_strlen($number) > 191
            || !(new ContractVariableDefinitionValidator)->date($agreementDate)
            || trim($key) === '' || mb_strlen($key) > 191) {
            throw new ContractBuilderException('contracts.supplementary_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $templateId, $templateVersion, $number, $agreementDate], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $templateId, $templateVersion, $number, $agreementDate, $key, $fingerprint): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $this->assertParty($actor, $organizationId, $contractId);
            $existing = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.supplementary_conflict', 409);
                }

                return $this->present($actor, $organizationId, $existing, true);
            }
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->first();
            if ($instance === null || $instance->effective_revision_id === null || $contract->getRawOriginal('status') !== 'active') {
                throw new ContractBuilderException('contracts.supplementary_not_effective', 409);
            }
            if (DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
                ->whereNotIn('status', ['applied', 'cancelled'])->exists()) {
                throw new ContractBuilderException('contracts.supplementary_open_exists', 409);
            }
            $revision = DB::table('contract_builder_revisions')->where('id', $instance->effective_revision_id)->firstOrFail();
            $previous = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
                ->where('status', 'applied')->orderByDesc('id')->first();
            $baseValues = $previous === null
                ? json_decode($revision->values, true, 512, JSON_THROW_ON_ERROR)
                : json_decode($previous->values, true, 512, JSON_THROW_ON_ERROR);
            $frame = $this->library->resolveTemplate($actor, $organizationId, $templateId, $templateVersion);
            $template = $this->library->read($actor, $organizationId, $templateId, $templateVersion);
            $parties = json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR);
            $definitions = json_decode($revision->definitions, true, 512, JSON_THROW_ON_ERROR);
            $entitySnapshots = json_decode($revision->entity_snapshots, true, 512, JSON_THROW_ON_ERROR);
            $revisionDocument = json_decode($revision->document, true, 512, JSON_THROW_ON_ERROR);
            $contentHash = $this->composer->contentHash([
                'frame' => $frame['document'], 'values' => (object) $baseValues,
                'number' => $number, 'agreement_date' => $agreementDate, 'parties' => $parties,
            ]);
            $id = DB::table('contract_supplementary_documents')->insertGetId([
                'contract_id' => $contractId, 'instance_id' => $instance->id,
                'base_revision_id' => $revision->id, 'previous_document_id' => $previous?->id,
                'template_version_id' => $template['version']['id'], 'author_organization_id' => $organizationId,
                'created_by' => $actor->id, 'number' => $number, 'agreement_date' => $agreementDate, 'status' => 'draft',
                'frame_document' => $this->json($frame['document']),
                'frame_definitions' => $this->json((object) $frame['definitions']),
                'revision_document' => $this->json($revisionDocument),
                'revision_definitions' => $this->json((object) $definitions),
                'parties' => $this->json($parties), 'entity_snapshots' => $this->json((object) $entitySnapshots),
                'base_values' => $this->json((object) $baseValues), 'values' => $this->json((object) $baseValues),
                'content_hash' => $contentHash, 'request_key' => $key, 'request_fingerprint' => $fingerprint,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('contract_supplementary_drafts')->insert([
                'document_id' => $id, 'version' => 1, 'values' => $this->json((object) $baseValues),
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->present($actor, $organizationId, DB::table('contract_supplementary_documents')->where('id', $id)->firstOrFail(), true);
        });
    }

    public function show(User $actor, int $organizationId, int $contractId, int $documentId): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $this->assertParty($actor, $organizationId, $contractId);
        $row = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->firstOrFail();

        return $this->present($actor, $organizationId, $row, true);
    }

    public function saveDraft(User $actor, int $organizationId, int $contractId, int $documentId, array $values, int $expectedVersion, string $key): array
    {
        $this->authorizeEdit($actor, $organizationId);
        if ($expectedVersion < 1 || trim($key) === '' || mb_strlen($key) > 191 || count($values) > 500
            || strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 10 * 1024 * 1024) {
            throw new ContractBuilderException('contracts.supplementary_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $expectedVersion, (object) $values], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $documentId, $values, $expectedVersion, $key, $fingerprint): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $this->assertParty($actor, $organizationId, $contractId);
            $document = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->lockForUpdate()->firstOrFail();
            $draft = DB::table('contract_supplementary_drafts')->where('document_id', $document->id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('contract_supplementary_draft_operations')->where('draft_id', $draft->id)->where('request_key', $key)->first();
            if ($operation !== null) {
                if (!hash_equals($operation->fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.supplementary_conflict', 409);
                }

                return json_decode($operation->response, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($document->status !== 'draft'
                || DB::table('contract_supplementary_confirmations')->where('document_id', $document->id)->exists()
                || (int) $draft->version !== $expectedVersion) {
                throw new ContractBuilderException('contracts.supplementary_conflict', 409);
            }
            $definitions = json_decode($document->revision_definitions, true, 512, JSON_THROW_ON_ERROR);
            $types = array_column($definitions, 'definition', 'id');
            $entitySnapshots = json_decode($document->entity_snapshots, true, 512, JSON_THROW_ON_ERROR);
            $sourceValues = app(ContractEntityCatalog::class)->sourceValues($actor, $organizationId, $types, $values, json_decode($document->base_values, true, 512, JSON_THROW_ON_ERROR));
            $resolved = (new ContractFormulaEngine)->calculate($types, $values, ContractEntityCatalog::accessible($entitySnapshots), static fn (string $id): mixed => $sourceValues[$id]);
            (new ContractDocumentRenderer)->render(
                json_decode($document->revision_document, true, 512, JSON_THROW_ON_ERROR),
                $definitions, $resolved, $entitySnapshots
            );
            $baseValues = json_decode($document->base_values, true, 512, JSON_THROW_ON_ERROR);
            $changes = $this->composer->changes($definitions, $baseValues, $resolved, $entitySnapshots);
            if ($changes === []) {
                throw new ContractBuilderException('contracts.supplementary_changes_required', 422);
            }
            $contentHash = $this->composer->contentHash([
                'frame' => json_decode($document->frame_document, true, 512, JSON_THROW_ON_ERROR),
                'values' => (object) $resolved, 'number' => $document->number,
                'agreement_date' => $document->agreement_date,
                'parties' => json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR),
            ]);
            DB::table('contract_supplementary_drafts')->where('id', $draft->id)->update([
                'version' => $expectedVersion + 1, 'values' => $this->json((object) $resolved),
                'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            DB::table('contract_supplementary_documents')->where('id', $document->id)->update([
                'values' => $this->json((object) $resolved), 'content_hash' => $contentHash, 'updated_at' => now(),
            ]);
            $response = $this->present($actor, $organizationId, DB::table('contract_supplementary_documents')->where('id', $document->id)->firstOrFail(), true);
            DB::table('contract_supplementary_draft_operations')->insert([
                'draft_id' => $draft->id, 'request_key' => $key, 'fingerprint' => $fingerprint,
                'response' => $this->json($response), 'created_at' => now(),
            ]);

            return $response;
        });
    }

    public function preview(User $actor, int $organizationId, int $contractId, int $documentId): array
    {
        $document = $this->show($actor, $organizationId, $contractId, $documentId);
        $row = DB::table('contract_supplementary_documents')->where('id', $documentId)->firstOrFail();
        $frameDefinitions = json_decode($row->frame_definitions, true, 512, JSON_THROW_ON_ERROR);
        $html = $this->composer->composeHtml(
            json_decode($row->frame_document, true, 512, JSON_THROW_ON_ERROR),
            $frameDefinitions, [], $document['changes'],
            json_decode($row->entity_snapshots, true, 512, JSON_THROW_ON_ERROR)
        );

        return ['document' => $document, 'html' => $html];
    }

    public function present(User $actor, int $organizationId, object $row, bool $detailed = false): array
    {
        $definitions = json_decode($row->revision_definitions, true, 512, JSON_THROW_ON_ERROR);
        $baseValues = json_decode($row->base_values, true, 512, JSON_THROW_ON_ERROR);
        $values = json_decode($row->values, true, 512, JSON_THROW_ON_ERROR);
        $entitySnapshots = json_decode($row->entity_snapshots, true, 512, JSON_THROW_ON_ERROR);
        $changes = $this->composer->changes($definitions, $baseValues, $values, $entitySnapshots);
        $confirmations = DB::table('contract_supplementary_confirmations')->where('document_id', $row->id)->count();
        $activation = DB::table('contract_supplementary_activations')->where('document_id', $row->id)
            ->where('status', '<>', 'cancelled')->orderByDesc('id')->first();
        $draft = DB::table('contract_supplementary_drafts')->where('document_id', $row->id)->first();
        $canEdit = $row->status === 'draft' && $confirmations === 0
            && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId]);
        $result = [
            'id' => (int) $row->id, 'number' => $row->number, 'agreement_date' => $row->agreement_date,
            'status' => $row->status, 'content_hash' => $row->content_hash, 'changes' => $changes,
            'can_edit' => $canEdit, 'fully_confirmed' => $confirmations === 2,
            'previous_document_id' => $row->previous_document_id === null ? null : (int) $row->previous_document_id,
            'activation' => $activation === null ? null : [
                'id' => (int) $activation->id, 'status' => $activation->status,
                'effective_date' => $activation->effective_date, 'basis' => $activation->basis,
                'error_message' => $activation->last_error === null ? null : trans_message($activation->last_error),
            ],
        ];
        if ($detailed) {
            $result['contract_id'] = (int) $row->contract_id;
            $result['base_revision_id'] = (int) $row->base_revision_id;
            $result['template_version_id'] = (int) $row->template_version_id;
            $result['values'] = (object) $values;
            $result['base_values'] = (object) $baseValues;
            $result['definitions'] = (object) $definitions;
            $result['draft_version'] = $draft === null ? null : (int) $draft->version;
            $result['change_amount'] = $row->change_amount === null ? null : (string) $row->change_amount;
            $result['parties'] = json_decode($row->parties, true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    public function load(int $contractId, int $documentId): object
    {
        return DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->firstOrFail();
    }

    private function assertParty(User $actor, int $organizationId, int $contractId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId) {
            throw new AuthorizationException;
        }
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->first();
        if ($instance === null || $instance->effective_revision_id === null) {
            if (!Contract::whereKey($contractId)->whereHas('parties', static fn ($q) => $q->where('linked_organization_id', $organizationId))->exists()) {
                throw new AuthorizationException;
            }

            return;
        }
        $parties = json_decode(DB::table('contract_builder_revisions')->where('id', $instance->effective_revision_id)->value('parties'), true, 512, JSON_THROW_ON_ERROR);
        if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
            throw new AuthorizationException;
        }
    }

    private function authorizeEdit(User $actor, int $organizationId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
