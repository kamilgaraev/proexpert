<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractBuilderDraftService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractBuilderInstanceService $instances,
    ) {}

    public function read(User $actor, int $organizationId, int $contractId): ?array
    {
        return DB::transaction(function () use ($actor, $organizationId, $contractId): ?array {
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $draft = DB::table('contract_builder_drafts')->where('instance_id', $instance->id)
                ->where('organization_id', $organizationId)->first();

            return $draft === null ? null : $this->present($actor, $organizationId, $contractId, $draft);
        });
    }

    public function save(User $actor, int $organizationId, int $contractId, int $baseRevision, int $expectedVersion, array $document, array $values, string $key, ?string $sourceRefreshHash = null, ?array $attachmentIds = null): array
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        $payload = [$baseRevision, $expectedVersion, $document, (object) $values];
        if ($sourceRefreshHash !== null) {
            if (preg_match('/^[a-f0-9]{64}$/D', $sourceRefreshHash) !== 1) {
                throw new ContractBuilderException('contracts.builder_input_invalid', 422);
            }
            $payload[] = $sourceRefreshHash;
        }
        $request = $this->json($payload);
        if ($attachmentIds !== null) {
            $request = $this->json([$payload, 'attachments' => $attachmentIds]);
        }
        if ($baseRevision < 1 || $expectedVersion < 0 || trim($key) === '' || mb_strlen($key) > 191
            || count($values) > 500 || strlen($request) > 10 * 1024 * 1024) {
            throw new ContractBuilderException('contracts.builder_input_invalid', 422);
        }
        $fingerprint = hash('sha256', $request);

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $baseRevision, $expectedVersion, $document, $values, $key, $fingerprint, $sourceRefreshHash, $attachmentIds): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            if (!$contract->parties()->where('linked_organization_id', $organizationId)->exists()) {
                throw new AuthorizationException;
            }
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $draft = DB::table('contract_builder_drafts')->where('instance_id', $instance->id)
                ->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ($draft !== null) {
                $operation = DB::table('contract_builder_draft_operations')->where('draft_id', $draft->id)->where('request_key', $key)->first();
                if ($operation !== null) {
                    if (!hash_equals($operation->fingerprint, $fingerprint)) {
                        $this->conflict();
                    }

                    return json_decode($operation->response, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $base = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)
                ->where('revision_number', $baseRevision)->firstOrFail();
            if (!ContractBuilderWorkflow::canRevise($contract, $instance) || (int) $instance->current_revision_id !== (int) $base->id
                || ($draft === null ? $expectedVersion !== 0 : (int) $draft->version !== $expectedVersion)) {
                $this->conflict();
            }
            $definitions = json_decode($base->definitions, true, 512, JSON_THROW_ON_ERROR);
            $types = array_column($definitions, 'definition', 'id');
            $sourceBasis = $draft !== null && (int) $draft->base_revision_id === (int) $base->id ? $draft : $base;
            $attachments = json_decode($sourceBasis->attachments ?? $base->attachments, true, 512, JSON_THROW_ON_ERROR);
            if ($attachmentIds !== null) {
                $attachments = app(ContractBuilderAssetService::class)->attachments($actor, $organizationId, $contractId, $attachmentIds,
                    json_decode($base->attachments, true, 512, JSON_THROW_ON_ERROR));
            }
            $frozen = json_decode($sourceBasis->entity_snapshots, true, 512, JSON_THROW_ON_ERROR);
            if ($sourceRefreshHash !== null) {
                $frozen = [];
            }
            $entitySnapshots = app(ContractEntityCatalog::class)->snapshots($actor, $organizationId, $types, $values, $frozen);
            $frozenValues = $sourceRefreshHash === null ? json_decode($sourceBasis->values, true, 512, JSON_THROW_ON_ERROR) : null;
            $sourceValues = app(ContractEntityCatalog::class)->sourceValues($actor, $organizationId, $types, $values, $frozenValues);
            if ($sourceRefreshHash !== null && !hash_equals($sourceRefreshHash, $this->sourceHash($entitySnapshots, $sourceValues))) {
                $this->conflict();
            }
            $resolved = (new ContractFormulaEngine)->calculate($types, $values, ContractEntityCatalog::accessible($entitySnapshots), static fn (string $id): mixed => $sourceValues[$id]);
            (new ContractDocumentRenderer)->render($document, $definitions, $resolved, $entitySnapshots);
            $data = [
                'base_revision_id' => $base->id, 'version' => $expectedVersion + 1,
                'document' => $this->json($document), 'values' => $this->json((object) $resolved),
                'entity_snapshots' => $this->json((object) $entitySnapshots),
                'attachments' => $this->json($attachments),
                'updated_by' => $actor->id, 'updated_at' => now(),
            ];
            if ($draft === null) {
                $draftId = DB::table('contract_builder_drafts')->insertGetId([
                    ...$data, 'instance_id' => $instance->id, 'organization_id' => $organizationId,
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
            } else {
                $draftId = $draft->id;
                DB::table('contract_builder_drafts')->where('id', $draftId)->update($data);
            }
            $response = $this->present($actor, $organizationId, $contractId, DB::table('contract_builder_drafts')->where('id', $draftId)->firstOrFail());
            DB::table('contract_builder_draft_operations')->insert([
                'draft_id' => $draftId, 'request_key' => $key, 'fingerprint' => $fingerprint,
                'response' => $this->json($response), 'created_at' => now(),
            ]);

            return $response;
        });
    }

    public function sourceChanges(User $actor, int $organizationId, int $contractId): array
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        $draft = $this->read($actor, $organizationId, $contractId);
        if ($draft === null) {
            throw new ContractBuilderException('contracts.builder_draft_missing', 404);
        }
        $snapshots = app(ContractEntityCatalog::class)->snapshots($actor, $organizationId,
            array_column($draft['definitions'], 'definition', 'id'), $draft['values']);
        $sourceValues = app(ContractEntityCatalog::class)->sourceValues($actor, $organizationId,
            array_column($draft['definitions'], 'definition', 'id'), $draft['values']);
        $types = array_column($draft['definitions'], 'definition', 'id');
        $input = array_filter($draft['values'], static fn (string $id): bool => ($types[$id]['source']['kind'] ?? 'manual') === 'manual', ARRAY_FILTER_USE_KEY);
        $updatedValues = (new ContractFormulaEngine)->calculate($types, $input, ContractEntityCatalog::accessible($snapshots), static fn (string $id): mixed => $sourceValues[$id]);
        $changes = [];
        foreach ($snapshots as $reference => $snapshot) {
            $before = $draft['entity_snapshots'][$reference]['label'] ?? null;
            if ($before !== $snapshot['label']) {
                $changes[] = ['reference' => $reference, 'before' => $before, 'after' => $snapshot['label']];
            }
        }
        foreach ($updatedValues as $id => $value) {
            $before = $draft['values'][$id] ?? null;
            if ($this->json($before) !== $this->json($value)) {
                $changes[] = ['reference' => $id, 'title' => $draft['definitions'][$id]['title'], 'before' => $before, 'after' => $value];
            }
        }

        return ['base_revision' => $draft['base_revision'], 'version' => $draft['version'], 'changes' => $changes,
            'source_hash' => $this->sourceHash($snapshots, $sourceValues)];
    }

    private function sourceHash(array $snapshots, array $values): string
    {
        return hash('sha256', $this->json(['entities' => (object) $snapshots, 'values' => (object) $values]));
    }

    public function preview(User $actor, int $organizationId, int $contractId): array
    {
        $draft = $this->read($actor, $organizationId, $contractId);
        if ($draft === null) {
            throw new ContractBuilderException('contracts.builder_draft_missing', 404);
        }

        return ['draft' => $draft, 'html' => (new ContractDocumentRenderer)->render($draft['document'], $draft['definitions'], $draft['values'], $draft['entity_snapshots'])];
    }

    private function present(User $actor, int $organizationId, int $contractId, object $draft): array
    {
        $base = DB::table('contract_builder_revisions')->where('id', $draft->base_revision_id)->where('instance_id', $draft->instance_id)->firstOrFail();
        $revision = $this->instances->read($actor, $organizationId, $contractId, (int) $base->revision_number);

        return [
            'id' => $draft->id, 'contract_id' => $contractId, 'organization_id' => $organizationId,
            'base_revision' => (int) $base->revision_number, 'version' => (int) $draft->version,
            'document' => json_decode($draft->document, true, 512, JSON_THROW_ON_ERROR),
            'values' => json_decode($draft->values, true, 512, JSON_THROW_ON_ERROR),
            'entity_snapshots' => json_decode($draft->entity_snapshots, true, 512, JSON_THROW_ON_ERROR),
            'definitions' => $revision['definitions'], 'blocks' => $revision['blocks'],
            'parties' => $revision['parties'], 'attachments' => $draft->attachments === null ? $revision['attachments'] : json_decode($draft->attachments, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function json(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (is_object($value)) {
                $properties = get_object_vars($value);
                ksort($properties);

                return (object) array_map($sort, $properties);
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }

                return array_map($sort, $value);
            }

            return $value;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function conflict(): never
    {
        throw new ContractBuilderException('contracts.builder_draft_conflict', 409);
    }
}
