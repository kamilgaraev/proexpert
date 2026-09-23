<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractSupplementaryConfirmationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractSupplementaryDocumentService $documents,
        private readonly ContractSupplementaryDocumentComposer $composer,
    ) {}

    public function confirm(User $actor, int $organizationId, int $contractId, int $documentId, string $hash, string $key, ?array $external = null): array
    {
        $permission = $external === null ? 'contracts.revisions.confirm' : 'contracts.revisions.record_external';
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || trim($key) === '' || mb_strlen($key) > 191) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        if ($external !== null && (!is_string($external['basis'] ?? null) || trim($external['basis']) === '' || mb_strlen($external['basis']) > 2000
            || !is_int($external['asset_id'] ?? null) || $external['asset_id'] < 1 || !in_array($external['side'] ?? null, ['first', 'second'], true))) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $documentId, $hash, $external], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $documentId, $hash, $key, $fingerprint, $external): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $document = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->lockForUpdate()->firstOrFail();
            $parties = json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR);
            $own = array_values(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId));
            if (count($own) !== 1) {
                throw new AuthorizationException;
            }
            $side = $own[0]['side'];
            if ($external !== null) {
                $target = array_values(array_filter($parties, static fn (array $party): bool => $party['side'] === $external['side'] && ($party['linked_organization_id'] ?? null) === null));
                if (count($target) !== 1 || $external['side'] === $side) {
                    throw new AuthorizationException;
                }
                $side = $external['side'];
            }
            $existing = DB::table('contract_supplementary_confirmations')->where('document_id', $document->id)
                ->where('recorded_organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.confirmation_conflict', 409);
                }

                return $this->present($existing);
            }
            $definitions = json_decode($document->revision_definitions, true, 512, JSON_THROW_ON_ERROR);
            $changes = $this->composer->changes(
                $definitions,
                json_decode($document->base_values, true, 512, JSON_THROW_ON_ERROR),
                json_decode($document->values, true, 512, JSON_THROW_ON_ERROR),
                json_decode($document->entity_snapshots, true, 512, JSON_THROW_ON_ERROR)
            );
            if ($document->status !== 'draft' || !hash_equals($document->content_hash, $hash)
                || DB::table('contract_supplementary_confirmations')->where('document_id', $document->id)->where('side', $side)->exists()) {
                throw new ContractBuilderException('contracts.confirmation_conflict', 409);
            }
            if ($changes === []) {
                throw new ContractBuilderException('contracts.supplementary_changes_required', 422);
            }
            $proof = $external === null ? null : app(ContractBuilderAssetService::class)->evidence($actor, $organizationId, $contractId, $external['asset_id']);
            $id = DB::table('contract_supplementary_confirmations')->insertGetId([
                'document_id' => $document->id, 'side' => $side,
                'party_organization_id' => $external === null ? $organizationId : null,
                'recorded_organization_id' => $organizationId, 'created_by' => $actor->id,
                'kind' => $external === null ? 'connected' : 'external', 'content_hash' => $hash,
                'basis' => $external['basis'] ?? null,
                'proof' => $proof === null ? null : json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now(),
            ]);

            return $this->present(DB::table('contract_supplementary_confirmations')->where('id', $id)->firstOrFail());
        });
    }

    public function state(User $actor, int $organizationId, int $contractId, int $documentId): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $document = $this->documents->load($contractId, $documentId);
        $items = DB::table('contract_supplementary_confirmations')->where('document_id', $document->id)->orderBy('side')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
        $parties = json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR);
        $own = array_values(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId));
        $externalSides = array_values(array_filter($parties, static fn (array $party): bool => ($party['linked_organization_id'] ?? null) === null && !in_array($party['side'], array_column($items, 'side'), true)));
        $editable = $document->status === 'draft';

        return [
            'document_id' => (int) $document->id, 'content_hash' => $document->content_hash,
            'items' => $items, 'fully_confirmed' => count($items) === 2,
            'external_sides' => $editable && count($own) === 1
                && $this->authorization->can($actor, 'contracts.revisions.record_external', ['organization_id' => $organizationId])
                    ? array_column($externalSides, 'side') : [],
            'can_confirm' => $editable && count($own) === 1 && !in_array($own[0]['side'], array_column($items, 'side'), true)
                && $this->authorization->can($actor, 'contracts.revisions.confirm', ['organization_id' => $organizationId]),
        ];
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'document_id' => (int) $row->document_id, 'side' => $row->side,
            'party_organization_id' => $row->party_organization_id, 'recorded_organization_id' => (int) $row->recorded_organization_id,
            'created_by' => (int) $row->created_by, 'created_at' => $row->created_at, 'kind' => $row->kind,
            'content_hash' => $row->content_hash, 'basis' => $row->basis,
            'proof' => $row->proof === null ? null : json_decode($row->proof, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
