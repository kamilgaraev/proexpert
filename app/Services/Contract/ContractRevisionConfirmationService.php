<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractRevisionConfirmationService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractOrganizationViewService $views) {}

    public function confirm(User $actor, int $organizationId, int $contractId, int $number, string $hash, string $key, ?array $external = null): array
    {
        $permission = $external === null ? 'contracts.revisions.confirm' : 'contracts.revisions.record_external';
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        if ($number < 1 || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || trim($key) === '' || mb_strlen($key) > 191) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        if ($external !== null && (!is_string($external['basis'] ?? null) || trim($external['basis']) === '' || mb_strlen($external['basis']) > 2000
            || !is_int($external['asset_id'] ?? null) || $external['asset_id'] < 1 || !in_array($external['side'] ?? null, ['first', 'second'], true))) {
            throw new ContractBuilderException('contracts.confirmation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $number, $hash, $external], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $number, $hash, $key, $fingerprint, $external): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $revision = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->where('revision_number', $number)->firstOrFail();
            $own = array_values(array_filter(json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR),
                static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId));
            if (count($own) !== 1) {
                throw new AuthorizationException;
            }
            $side = $own[0]['side'];
            if ($external !== null) {
                $parties = json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR);
                $target = array_values(array_filter($parties, static fn (array $party): bool => $party['side'] === $external['side'] && ($party['linked_organization_id'] ?? null) === null));
                if (count($target) !== 1 || $external['side'] === $side) {
                    throw new AuthorizationException;
                }
                $side = $external['side'];
            }
            $existing = DB::table('contract_revision_confirmations')->where('instance_id', $instance->id)
                ->where('recorded_organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint)) {
                    $this->conflict();
                }
                return $this->present($existing);
            }
            if ((int) $instance->current_revision_id !== (int) $revision->id || !hash_equals($revision->content_hash, $hash)
                || !ContractBuilderWorkflow::canRevise($contract, $instance)
                || DB::table('contract_revision_confirmations')->where('revision_id', $revision->id)->where('side', $side)->exists()) {
                $this->conflict();
            }
            $proof = $external === null ? null : app(ContractBuilderAssetService::class)->evidence($actor, $organizationId, $contractId, $external['asset_id']);
            $id = DB::table('contract_revision_confirmations')->insertGetId([
                'instance_id' => $instance->id, 'revision_id' => $revision->id, 'side' => $side,
                'party_organization_id' => $external === null ? $organizationId : null, 'recorded_organization_id' => $organizationId,
                'created_by' => $actor->id, 'kind' => $external === null ? 'connected' : 'external', 'content_hash' => $hash,
                'basis' => $external['basis'] ?? null, 'proof' => $proof === null ? null : json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now(),
            ]);

            return $this->present(DB::table('contract_revision_confirmations')->where('id', $id)->firstOrFail());
        });
    }

    public function state(User $actor, int $organizationId, int $contractId, int $number): array
    {
        $view = $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $revision = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->where('revision_number', $number)->firstOrFail();
        $items = DB::table('contract_revision_confirmations')->where('revision_id', $revision->id)->orderBy('side')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
        $parties = json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR);
        $own = array_values(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId));
        $externalSides = array_values(array_filter($parties, static fn (array $party): bool => ($party['linked_organization_id'] ?? null) === null && !in_array($party['side'], array_column($items, 'side'), true)));

        return ['revision_id' => (int) $revision->id, 'revision_number' => $number, 'content_hash' => $revision->content_hash,
            'items' => $items, 'fully_confirmed' => count($items) === 2,
            'external_sides' => (int) $instance->current_revision_id === (int) $revision->id && ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && count($own) === 1 && $this->authorization->can($actor, 'contracts.revisions.record_external', ['organization_id' => $organizationId])
                    ? array_column($externalSides, 'side') : [],
            'can_confirm' => (int) $instance->current_revision_id === (int) $revision->id && ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && count($own) === 1 && !in_array($own[0]['side'], array_column($items, 'side'), true)
                && $this->authorization->can($actor, 'contracts.revisions.confirm', ['organization_id' => $organizationId])];
    }

    private function present(object $row): array
    {
        return ['id' => (int) $row->id, 'revision_id' => (int) $row->revision_id, 'side' => $row->side,
            'party_organization_id' => $row->party_organization_id, 'recorded_organization_id' => (int) $row->recorded_organization_id,
            'created_by' => (int) $row->created_by, 'created_at' => $row->created_at, 'kind' => $row->kind,
            'content_hash' => $row->content_hash, 'basis' => $row->basis, 'proof' => $row->proof === null ? null : json_decode($row->proof, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function conflict(): never
    {
        throw new ContractBuilderException('contracts.confirmation_conflict', 409);
    }
}
