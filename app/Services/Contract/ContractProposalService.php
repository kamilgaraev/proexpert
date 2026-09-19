<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractProposalService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractBuilderDraftService $drafts,
    ) {}

    public function create(User $actor, int $organizationId, int $contractId, int $baseRevision, int $draftVersion, string $message, string $key): array
    {
        $this->authorize($actor, $organizationId, 'contracts.edit');
        $this->input($key, $message);
        if ($baseRevision < 1 || $draftVersion < 1) {
            $this->invalid();
        }
        $fingerprint = hash('sha256', $this->json([$actor->id, $baseRevision, $draftVersion, $message]));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $baseRevision, $draftVersion, $message, $key, $fingerprint): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $existing = DB::table('contract_builder_proposals')->where('instance_id', $instance->id)
                ->where('author_organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    $this->conflict();
                }
                return $this->present($existing);
            }
            $draft = $this->drafts->read($actor, $organizationId, $contractId);
            $base = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->where('revision_number', $baseRevision)->firstOrFail();
            if (!ContractBuilderWorkflow::canRevise($contract, $instance) || $draft === null || $draft['version'] !== $draftVersion
                || $draft['base_revision'] !== $baseRevision || (int) $instance->current_revision_id !== (int) $base->id) {
                $this->conflict();
            }
            $parties = $draft['parties'];
            $own = array_values(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId));
            $opposite = array_values(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) !== $organizationId));
            if (count($own) !== 1 || count($opposite) !== 1) {
                throw new AuthorizationException;
            }
            $content = Arr::only($draft, ['document', 'definitions', 'blocks', 'values', 'entity_snapshots', 'parties', 'attachments']);
            foreach (['definitions', 'blocks', 'values', 'entity_snapshots'] as $map) {
                $content[$map] = (object) $content[$map];
            }
            $id = DB::table('contract_builder_proposals')->insertGetId([
                'instance_id' => $instance->id, 'base_revision_id' => $base->id, 'draft_id' => $draft['id'], 'draft_version' => $draftVersion,
                'author_organization_id' => $organizationId, 'recipient_organization_id' => $opposite[0]['linked_organization_id'] ?? null,
                'created_by' => $actor->id, 'message' => $message, 'content' => $this->json($content),
                'content_hash' => hash('sha256', $this->json([$base->template_version_id, $content])),
                'request_key' => $key, 'request_fingerprint' => $fingerprint, 'revision_request_key' => (string) Str::uuid(), 'created_at' => now(),
            ]);

            return $this->present(DB::table('contract_builder_proposals')->where('id', $id)->firstOrFail());
        });
    }

    public function decide(User $actor, int $organizationId, int $contractId, int $proposalId, int $expectedVersion, string $decision, string $reason, string $key, ?array $external = null): array
    {
        $this->authorize($actor, $organizationId, $external === null ? 'contracts.revisions.accept' : 'contracts.revisions.record_external');
        $this->input($key, $reason);
        if (!in_array($decision, ['accepted', 'rejected'], true) || $expectedVersion < 1) {
            $this->invalid();
        }
        if ($external !== null && (!is_string($external['basis'] ?? null) || trim($external['basis']) === '' || mb_strlen($external['basis']) > 2000
            || !is_int($external['asset_id'] ?? null) || $external['asset_id'] < 1)) {
            $this->invalid();
        }
        $fingerprint = hash('sha256', $this->json([$actor->id, $organizationId, $expectedVersion, $decision, $reason, $external]));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $proposalId, $expectedVersion, $decision, $reason, $key, $fingerprint, $external): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $proposal = DB::table('contract_builder_proposals')->where('instance_id', $instance->id)->where('id', $proposalId)->lockForUpdate()->firstOrFail();
            if ($external === null
                ? ((int) $proposal->author_organization_id === $organizationId || (int) $proposal->recipient_organization_id !== $organizationId)
                : ($proposal->recipient_organization_id !== null || (int) $proposal->author_organization_id !== $organizationId)) {
                throw new AuthorizationException;
            }
            if ($proposal->status !== 'pending') {
                if ($proposal->decision_key !== $key || !hash_equals($proposal->decision_fingerprint, $fingerprint)) {
                    $this->conflict();
                }
                return $this->present($proposal);
            }
            if ((int) $proposal->lock_version !== $expectedVersion || !ContractBuilderWorkflow::canRevise($contract, $instance)) {
                $this->conflict();
            }
            $revisionId = null;
            $proof = $external === null ? null : app(ContractBuilderAssetService::class)->evidence($actor, $organizationId, $contractId, $external['asset_id']);
            if ($decision === 'accepted') {
                if ((int) $instance->current_revision_id !== (int) $proposal->base_revision_id) {
                    $this->conflict();
                }
                $base = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->where('id', $proposal->base_revision_id)->firstOrFail();
                $content = json_decode($proposal->content, true, 512, JSON_THROW_ON_ERROR);
                (new ContractDocumentRenderer)->render($content['document'], $content['definitions'], $content['values'], $content['entity_snapshots']);
                foreach (['definitions', 'blocks', 'values', 'entity_snapshots'] as $map) {
                    $content[$map] = (object) $content[$map];
                }
                $revisionId = DB::table('contract_builder_revisions')->insertGetId([
                    'instance_id' => $instance->id, 'revision_number' => (int) $base->revision_number + 1, 'base_revision_id' => $base->id,
                    'template_version_id' => $base->template_version_id, 'author_organization_id' => $proposal->author_organization_id,
                    'created_by' => $actor->id, 'created_at' => now(), 'content_hash' => $proposal->content_hash,
                    'request_key' => $proposal->revision_request_key, 'request_fingerprint' => $proposal->request_fingerprint,
                    ...array_map(fn ($value): string => $this->json($value), $content),
                ]);
                DB::table('contract_builder_instances')->where('id', $instance->id)->update([
                    'current_revision_id' => $revisionId, 'lock_version' => (int) $instance->lock_version + 1, 'updated_at' => now(),
                ]);
            }
            DB::table('contract_builder_proposals')->where('id', $proposalId)->update([
                'status' => $decision, 'decided_by' => $actor->id, 'decision_organization_id' => $organizationId,
                'decision_reason' => $reason, 'decision_key' => $key, 'decision_fingerprint' => $fingerprint,
                'decision_kind' => $external === null ? 'connected' : 'external', 'decision_basis' => $external['basis'] ?? null,
                'decision_proof' => $proof === null ? null : $this->json($proof),
                'decided_at' => now(), 'accepted_revision_id' => $revisionId, 'lock_version' => $expectedVersion + 1,
            ]);

            return $this->present(DB::table('contract_builder_proposals')->where('id', $proposalId)->firstOrFail());
        });
    }

    public function show(User $actor, int $organizationId, int $contractId, int $proposalId): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $proposal = DB::table('contract_builder_proposals')->where('instance_id', $instance->id)->where('id', $proposalId)
            ->where(fn ($query) => $query->where('author_organization_id', $organizationId)->orWhere('recipient_organization_id', $organizationId))->firstOrFail();

        return $this->present($proposal);
    }

    public function listing(User $actor, int $organizationId, int $contractId, ?int $before = null): array
    {
        $view = $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $rows = DB::table('contract_builder_proposals')->where('instance_id', $instance->id)
            ->select(['id', 'base_revision_id', 'draft_version', 'author_organization_id', 'recipient_organization_id',
                'message', 'status', 'lock_version', 'created_by', 'created_at', 'decided_by', 'decided_at', 'decision_reason', 'decision_kind', 'decision_basis', 'decision_proof', 'accepted_revision_id', 'content_hash'])
            ->where(fn ($query) => $query->where('author_organization_id', $organizationId)->orWhere('recipient_organization_id', $organizationId))
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))->orderByDesc('id')->limit(21)->get();
        $items = $rows->take(20)->map(fn (object $row): array => Arr::except($this->present($row), ['content']))->all();

        return ['items' => $items, 'next_cursor' => $rows->count() > 20 ? end($items)['id'] : null,
            'can_decide' => ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && $this->authorization->can($actor, 'contracts.revisions.accept', ['organization_id' => $organizationId]),
            'can_record_external' => ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && $this->authorization->can($actor, 'contracts.revisions.record_external', ['organization_id' => $organizationId])];
    }

    public function preview(User $actor, int $organizationId, int $contractId, int $proposalId): array
    {
        $proposal = $this->show($actor, $organizationId, $contractId, $proposalId);
        $content = $proposal['content'];

        return ['proposal' => $proposal, 'html' => (new ContractDocumentRenderer)->render($content['document'], $content['definitions'], $content['values'], $content['entity_snapshots'])];
    }

    private function present(object $proposal): array
    {
        $result = [
            'id' => (int) $proposal->id, 'base_revision_id' => (int) $proposal->base_revision_id, 'draft_version' => (int) $proposal->draft_version,
            'author_organization_id' => (int) $proposal->author_organization_id,
            'recipient_organization_id' => $proposal->recipient_organization_id === null ? null : (int) $proposal->recipient_organization_id,
            'message' => $proposal->message, 'status' => $proposal->status, 'version' => (int) $proposal->lock_version,
            'created_by' => (int) $proposal->created_by, 'created_at' => $proposal->created_at,
            'decided_by' => $proposal->decided_by, 'decided_at' => $proposal->decided_at, 'decision_reason' => $proposal->decision_reason,
            'accepted_revision_id' => $proposal->accepted_revision_id, 'content_hash' => $proposal->content_hash,
            'decision_kind' => $proposal->decision_kind, 'decision_basis' => $proposal->decision_basis,
            'decision_proof' => $proposal->decision_proof === null ? null : json_decode($proposal->decision_proof, true, 512, JSON_THROW_ON_ERROR),
        ];
        if (isset($proposal->content)) {
            $result['content'] = json_decode($proposal->content, true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    private function authorize(User $actor, int $organizationId, string $permission): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function input(string $key, string $text): void
    {
        if (trim($key) === '' || mb_strlen($key) > 191 || mb_strlen($text) > 2000) {
            $this->invalid();
        }
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

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.proposal_input_invalid', 422);
    }

    private function conflict(): never
    {
        throw new ContractBuilderException('contracts.proposal_conflict', 409);
    }
}
