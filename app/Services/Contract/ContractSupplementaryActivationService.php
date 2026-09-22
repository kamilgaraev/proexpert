<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractSupplementaryActivationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractSupplementaryDocumentService $documents,
        private readonly ContractSupplementaryDocumentComposer $composer,
        private readonly ContractRevisionTermsCompiler $compiler,
    ) {}

    public function schedule(User $actor, int $organizationId, int $contractId, int $documentId, string $hash, ?int $previousDocumentId, string $date, string $basis, string $key): array
    {
        $this->authorize($actor, $organizationId);
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || trim($basis) === '' || mb_strlen($basis) > 2000
            || trim($key) === '' || mb_strlen($key) > 191 || !(new ContractVariableDefinitionValidator)->date($date)) {
            throw new ContractBuilderException('contracts.activation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $documentId, $hash, $previousDocumentId, $date, $basis], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $documentId, $hash, $previousDocumentId, $date, $basis, $key, $fingerprint): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $document = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->lockForUpdate()->firstOrFail();
            $parties = json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR);
            $own = array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId);
            if (count($own) !== 1) {
                throw new AuthorizationException;
            }
            $existing = DB::table('contract_supplementary_activations')->where('document_id', $document->id)
                ->where('organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.activation_conflict', 409);
                }

                return $this->present($existing);
            }
            $lastApplied = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
                ->where('status', 'applied')->orderByDesc('id')->value('id');
            $lastAppliedId = $lastApplied === null ? null : (int) $lastApplied;
            $definitions = json_decode($document->revision_definitions, true, 512, JSON_THROW_ON_ERROR);
            $values = json_decode($document->values, true, 512, JSON_THROW_ON_ERROR);
            $changes = $this->composer->changes(
                $definitions,
                json_decode($document->base_values, true, 512, JSON_THROW_ON_ERROR),
                $values,
                json_decode($document->entity_snapshots, true, 512, JSON_THROW_ON_ERROR)
            );
            if ($document->status !== 'draft' || $changes === [] || $lastAppliedId !== $previousDocumentId
                || !hash_equals($document->content_hash, $hash) || $date < now()->toDateString()
                || DB::table('contract_supplementary_activations')->where('document_id', $document->id)->whereIn('status', ['scheduled', 'failed'])->exists()
                || DB::table('contract_supplementary_confirmations')->where('document_id', $document->id)->where('content_hash', $hash)->distinct()->count('side') !== 2) {
                throw new ContractBuilderException('contracts.activation_conflict', 409);
            }
            $revision = [
                'document' => json_decode($document->revision_document, true, 512, JSON_THROW_ON_ERROR),
                'definitions' => $definitions,
                'values' => $values,
                'entity_snapshots' => json_decode($document->entity_snapshots, true, 512, JSON_THROW_ON_ERROR),
            ];
            $plan = $this->compiler->compile($revision);
            $previous = [];
            foreach (array_keys($plan['terms']) as $field) {
                $previous[$field] = $contract->getRawOriginal($field);
            }
            $id = DB::table('contract_supplementary_activations')->insertGetId([
                'document_id' => $document->id, 'previous_document_id' => $previousDocumentId,
                'organization_id' => $organizationId, 'created_by' => $actor->id, 'content_hash' => $hash,
                'basis' => $basis, 'effective_date' => $date, 'request_key' => $key, 'fingerprint' => $fingerprint,
                'plan' => json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'previous_terms' => json_encode((object) $previous, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->present(DB::table('contract_supplementary_activations')->where('id', $id)->firstOrFail());
        });
    }

    public function preview(User $actor, int $organizationId, int $contractId, int $documentId): array
    {
        $document = $this->documents->show($actor, $organizationId, $contractId, $documentId);
        $row = $this->documents->load($contractId, $documentId);
        $revision = [
            'document' => json_decode($row->revision_document, true, 512, JSON_THROW_ON_ERROR),
            'definitions' => json_decode($row->revision_definitions, true, 512, JSON_THROW_ON_ERROR),
            'values' => json_decode($row->values, true, 512, JSON_THROW_ON_ERROR),
            'entity_snapshots' => json_decode($row->entity_snapshots, true, 512, JSON_THROW_ON_ERROR),
        ];
        $plan = $this->compiler->compile($revision);
        $contract = Contract::findOrFail($contractId);
        $changes = [];
        foreach ($plan['terms'] as $field => $value) {
            $changes[] = ['field' => $field, 'before' => $contract->getRawOriginal($field), 'after' => $value];
        }

        return [
            'document_id' => (int) $document['id'], 'content_hash' => $document['content_hash'],
            'plan' => ['terms' => (object) $plan['terms'], 'works' => $plan['works'], 'bases' => (object) $plan['bases']],
            'changes' => $changes,
        ];
    }

    public function cancel(User $actor, int $organizationId, int $contractId, int $documentId, int $activationId, string $basis, string $key): array
    {
        $this->authorize($actor, $organizationId);
        if (trim($basis) === '' || mb_strlen($basis) > 2000 || trim($key) === '' || mb_strlen($key) > 191) {
            throw new ContractBuilderException('contracts.activation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $organizationId, $basis], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $documentId, $activationId, $basis, $key, $fingerprint): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $document = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->lockForUpdate()->firstOrFail();
            $row = DB::table('contract_supplementary_activations')->where('document_id', $document->id)->where('id', $activationId)->lockForUpdate()->firstOrFail();
            $parties = json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR);
            if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
                throw new AuthorizationException;
            }
            if ($row->status === 'cancelled') {
                if ($row->cancellation_key !== $key || !hash_equals($row->cancellation_fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.activation_conflict', 409);
                }

                return $this->present($row);
            }
            if ($row->status === 'applied') {
                throw new ContractBuilderException('contracts.activation_conflict', 409);
            }
            DB::table('contract_supplementary_activations')->where('id', $row->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancelled_organization_id' => $organizationId, 'cancellation_basis' => $basis,
                'cancellation_key' => $key, 'cancellation_fingerprint' => $fingerprint, 'updated_at' => now(),
            ]);
            if ($document->status === 'draft') {
                DB::table('contract_supplementary_documents')->where('id', $document->id)->update([
                    'status' => 'cancelled', 'updated_at' => now(),
                ]);
            }

            return $this->present(DB::table('contract_supplementary_activations')->where('id', $row->id)->firstOrFail());
        });
    }

    public function authorize(User $actor, int $organizationId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.revisions.activate', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    public function present(object $row, bool $includePlan = true): array
    {
        $result = [
            'id' => (int) $row->id, 'document_id' => (int) $row->document_id,
            'previous_document_id' => $row->previous_document_id === null ? null : (int) $row->previous_document_id,
            'organization_id' => (int) $row->organization_id, 'created_by' => (int) $row->created_by,
            'content_hash' => $row->content_hash, 'basis' => $row->basis, 'effective_date' => $row->effective_date,
            'status' => $row->status, 'attempts' => (int) $row->attempts, 'last_error' => $row->last_error,
            'error_message' => $row->last_error === null ? null : trans_message($row->last_error),
            'applied_at' => $row->applied_at, 'created_at' => $row->created_at,
            'cancelled_at' => $row->cancelled_at, 'cancellation_basis' => $row->cancellation_basis,
        ];
        if ($includePlan) {
            $result['plan'] = json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR);
            $result['result'] = $row->result === null ? null : json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }
}
