<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContractProposalReviewService
{
    public function read(User $actor, int $organizationId, int $contractId, int $proposalId): array
    {
        $proposal = app(ContractProposalService::class)->show($actor, $organizationId, $contractId, $proposalId);
        $base = DB::table('contract_builder_revisions')->where('id', $proposal['base_revision_id'])->firstOrFail();
        $before = [];
        foreach (['document', 'definitions', 'values', 'entity_snapshots', 'parties', 'attachments'] as $field) { $before[$field] = json_decode($base->$field, true, 512, JSON_THROW_ON_ERROR); }
        $recipients = DB::table('contract_builder_proposal_recipients')->where('proposal_id', $proposalId)->get();
        return ['content_hash' => $proposal['content_hash'], 'version' => $proposal['version'], 'status' => $proposal['status'],
            'diff' => (new ContractProposalDiff)->compare($before, $proposal['content']),
            'comments' => DB::table('contract_proposal_comments')->where('proposal_id', $proposalId)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'decisions' => DB::table('contract_proposal_clause_decisions')->whereIn('recipient_id', $recipients->pluck('id'))->orderBy('id')->get()->map(static fn (object $row): array => [
                ...(array) $row, 'proof' => $row->proof === null ? null : json_decode($row->proof, true, 512, JSON_THROW_ON_ERROR),
                'party_key' => $recipients->firstWhere('id', $row->recipient_id)->party_key,
            ])->all(),
            'can_comment' => $proposal['status'] === 'pending' && $this->canComment($actor, $organizationId),
            'can_decide' => $proposal['status'] === 'pending' && $recipients->contains(static fn (object $row): bool => (int) ($row->party_organization_id ?? 0) === $organizationId && $row->status === 'pending')
                && $this->can($actor, $organizationId, 'contracts.revisions.accept'),
            'can_record_external' => $proposal['status'] === 'pending' && $proposal['author_organization_id'] === $organizationId
                && $this->can($actor, $organizationId, 'contracts.revisions.record_external')];
    }

    public function comment(User $actor, int $organizationId, int $contractId, int $proposalId, array $input): array
    {
        if (!$this->canComment($actor, $organizationId)) { throw new AuthorizationException; }
        return $this->locked($actor, $organizationId, $contractId, $proposalId, function (array $proposal) use ($actor, $organizationId, $input): array {
            $body = trim($input['body'] ?? '');
            if ($body === '' || mb_strlen($body) > 2000) { throw ValidationException::withMessages(['body' => 'Введите комментарий до 2000 символов.']); }
            $clause = $input['clause_id'] ?? null;
            $blocking = (bool) ($input['blocking'] ?? false);
            $fingerprint = $this->fingerprint([...$input, 'organization_id' => $organizationId, 'actor_id' => $actor->id]);
            $existing = DB::table('contract_proposal_comments')->where('proposal_id', $proposal['id'])->where('created_by', $actor->id)->where('request_key', $input['request_key'])->first();
            if ($existing) { $this->same($existing, $fingerprint); return (array) $existing; }
            $this->pending($proposal, $input);
            $this->clause($proposal, $clause, $blocking);
            if ($blocking && DB::table('contract_builder_proposal_recipients')->where('proposal_id', $proposal['id'])->where('status', '<>', 'pending')->exists()) {
                throw ValidationException::withMessages(['blocking' => 'Получатель уже принял решение. Для нового спорного условия подготовьте новое предложение.']);
            }
            $id = DB::table('contract_proposal_comments')->insertGetId(['proposal_id' => $proposal['id'], 'organization_id' => $organizationId,
                'created_by' => $actor->id, 'clause_id' => $clause, 'blocking' => $blocking, 'body' => $body,
                'content_hash' => $proposal['content_hash'], 'request_key' => $input['request_key'], 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            return (array) DB::table('contract_proposal_comments')->where('id', $id)->firstOrFail();
        });
    }

    public function decideClause(User $actor, int $organizationId, int $contractId, int $proposalId, array $input, bool $external = false): array
    {
        if (!$this->can($actor, $organizationId, $external ? 'contracts.revisions.record_external' : 'contracts.revisions.accept')) { throw new AuthorizationException; }
        return $this->locked($actor, $organizationId, $contractId, $proposalId, function (array $proposal) use ($actor, $organizationId, $contractId, $input, $external): array {
            if ($external && $proposal['author_organization_id'] !== $organizationId) { throw new AuthorizationException; }
            $recipient = DB::table('contract_builder_proposal_recipients')->where('proposal_id', $proposal['id'])
                ->when($external, fn ($query) => $query->whereNull('party_organization_id')->where('party_key', $input['party_key'] ?? ''),
                    fn ($query) => $query->where('party_organization_id', $organizationId))->first();
            if (!$recipient) { throw new AuthorizationException; }
            $fingerprint = $this->fingerprint([...$input, 'organization_id' => $organizationId, 'actor_id' => $actor->id]);
            $existing = DB::table('contract_proposal_clause_decisions')->where('recipient_id', $recipient->id)->where('created_by', $actor->id)->where('request_key', $input['request_key'])->first();
            if ($existing) { $this->same($existing, $fingerprint); return (array) $existing; }
            $this->pending($proposal, $input);
            $this->clause($proposal, $input['clause_id'], true);
            if ($recipient->status !== 'pending' || DB::table('contract_proposal_clause_decisions')->where('recipient_id', $recipient->id)->where('clause_id', $input['clause_id'])->exists()) {
                throw new ContractBuilderException('contracts.proposal_conflict', 409);
            }
            $reason = trim($input['reason'] ?? '');
            if ($reason === '' || mb_strlen($reason) > 2000 || !in_array($input['decision'] ?? null, ['accepted', 'rejected'], true)) {
                throw ValidationException::withMessages(['reason' => trans_message('contracts.proposal_clause_reason_invalid')]);
            }
            $proof = null;
            if ($external) {
                if (trim($input['basis'] ?? '') === '' || mb_strlen($input['basis']) > 2000 || !is_int($input['asset_id'] ?? null)) {
                    throw ValidationException::withMessages(['basis' => trans_message('contracts.proposal_clause_external_evidence_required')]);
                }
                $proof = app(ContractBuilderAssetService::class)->evidence($actor, $organizationId, $contractId, $input['asset_id']);
            }
            $id = DB::table('contract_proposal_clause_decisions')->insertGetId(['recipient_id' => $recipient->id, 'organization_id' => $organizationId,
                'created_by' => $actor->id, 'clause_id' => $input['clause_id'], 'decision' => $input['decision'], 'reason' => $reason,
                'basis' => $external ? $input['basis'] : null, 'proof' => $proof === null ? null : json_encode($proof, JSON_THROW_ON_ERROR),
                'content_hash' => $proposal['content_hash'], 'request_key' => $input['request_key'], 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            return (array) DB::table('contract_proposal_clause_decisions')->where('id', $id)->firstOrFail();
        });
    }

    public function assertAcceptedClauses(int $proposalId, int $recipientId): void
    {
        $blocking = DB::table('contract_proposal_comments')->where('proposal_id', $proposalId)->where('blocking', true)->distinct()->pluck('clause_id');
        foreach ($blocking as $clause) {
            if (!DB::table('contract_proposal_clause_decisions')->where('recipient_id', $recipientId)->where('clause_id', $clause)->where('decision', 'accepted')->exists()) {
                throw ValidationException::withMessages(['decision' => trans_message('contracts.proposal_clause_decisions_required')]);
            }
        }
        if (DB::table('contract_proposal_clause_decisions')->where('recipient_id', $recipientId)->where('decision', 'rejected')->exists()) {
            throw ValidationException::withMessages(['decision' => trans_message('contracts.proposal_clause_rejected')]);
        }
    }

    private function locked(User $actor, int $organizationId, int $contractId, int $proposalId, callable $operation): array
    {
        return DB::transaction(function () use ($actor, $organizationId, $contractId, $proposalId, $operation): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $proposal = app(ContractProposalService::class)->show($actor, $organizationId, $contractId, $proposalId);
            DB::table('contract_builder_proposals')->where('id', $proposalId)->lockForUpdate()->firstOrFail();
            return $operation($proposal);
        });
    }

    private function pending(array $proposal, array $input): void
    {
        if ($proposal['status'] !== 'pending' || !hash_equals($proposal['content_hash'], $input['content_hash'] ?? '')
            || trim($input['request_key'] ?? '') === '' || mb_strlen($input['request_key'] ?? '') > 191) {
            throw new ContractBuilderException('contracts.proposal_conflict', 409);
        }
    }

    private function clause(array $proposal, ?string $id, bool $required): void
    {
        if ($id === null && !$required) { return; }
        $found = false;
        $visit = function (array $node) use (&$visit, &$found, $id): void {
            if ($node['type'] === 'clause' && $node['attrs']['id'] === $id) { $found = true; }
            foreach ($node['content'] ?? [] as $child) { $visit($child); }
        };
        $visit($proposal['content']['document']);
        if (!$found) { throw ValidationException::withMessages(['clause_id' => trans_message('contracts.proposal_clause_unknown')]); }
    }

    private function canComment(User $actor, int $organizationId): bool
    {
        return $this->can($actor, $organizationId, 'contracts.edit') || $this->can($actor, $organizationId, 'contracts.revisions.accept')
            || $this->can($actor, $organizationId, 'contracts.revisions.record_external');
    }

    private function can(User $actor, int $organizationId, string $permission): bool
    {
        return (int) $actor->current_organization_id === $organizationId
            && app(AuthorizationService::class)->can($actor, $permission, ['organization_id' => $organizationId]);
    }

    private function fingerprint(array $input): string
    {
        ksort($input);
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function same(object $existing, string $fingerprint): void
    {
        if (!hash_equals($existing->request_fingerprint, $fingerprint)) { throw new ContractBuilderException('contracts.proposal_conflict', 409); }
    }
}
