<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatementLine;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementDraftEditRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use App\Exceptions\BusinessLogicException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WorkVolumeStatementService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $access, private readonly WorkVolumeAcceptedAllocationService $acceptedAllocations) {}

    public function createDraft(User $actor, int $projectId, array $payload): WorkVolumeStatement
    {
        $project = $this->project($actor, $projectId, 'budget-estimates.edit');
        $this->assertSourceMetadataNotProvided($payload);
        $organizationId = (int) $actor->current_organization_id;
        return DB::transaction(function () use ($project, $organizationId, $payload): WorkVolumeStatement {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (! empty($payload['operation_key'])) {
                $replayed = WorkVolumeStatement::query()->where('organization_id', $organizationId)
                    ->where('project_id', $project->id)->where('operation_key', $payload['operation_key'])->first();
                if ($replayed !== null) {
                    if ($replayed->operation_hash !== $this->operationHash($payload, 0)) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
                    }
                    return $replayed->load('lines');
                }
            }
            $lines = $this->validateLines($payload['lines'] ?? [], $organizationId);
            $this->assertEstimateItemsScope($project, $organizationId, $lines);
            $key = (string) ($payload['statement_key'] ?? Str::uuid());
            if (WorkVolumeStatement::query()->where('organization_id', $organizationId)
                ->where('project_id', $project->id)->where('statement_key', $key)->exists()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.statement_key_exists'), 409);
            }
            $statement = WorkVolumeStatement::create([
                'organization_id' => $organizationId, 'project_id' => $project->id,
                'statement_key' => $key, 'version' => 1,
                'operation_key' => $payload['operation_key'] ?? null,
                'operation_hash' => ! empty($payload['operation_key']) ? $this->operationHash($payload, 0) : null,
                'name' => (string) ($payload['name'] ?? 'Ведомость объёмов работ'),
                'status' => WorkVolumeStatement::STATUS_DRAFT,
                'basis_revision' => $payload['basis_revision'] ?? null,
            ]);
            $statement->lines()->createMany($lines);
            return $statement->load('lines');
        });
    }

    public function assertProjectAccess(User $actor, int $projectId, string $permission = 'budget-estimates.view'): void
    {
        $this->project($actor, $projectId, $permission);
    }

    public function compareRevisions(User $actor, WorkVolumeStatement $before, WorkVolumeStatement $after): array
    {
        $this->assertScope($actor, $before, 'budget-estimates.view');
        $this->assertScope($actor, $after, 'budget-estimates.view');
        if ((int) $before->organization_id !== (int) $after->organization_id
            || (int) $before->project_id !== (int) $after->project_id
            || $before->statement_key !== $after->statement_key) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.comparison_scope_invalid'), 422);
        }
        $fields = ['id', 'version', 'based_on_statement_id', 'name', 'status', 'change_reason', 'basis_revision', 'source_file_hash', 'approved_at', 'approved_by_user_id'];
        return [
            'before' => $before->only($fields),
            'after' => $after->only($fields),
            'lines' => (new WorkVolumeStatementDiff())->compare($before->lines()->get()->toArray(), $after->lines()->get()->toArray()),
        ];
    }

    public function createRevision(User $actor, WorkVolumeStatement $statement, array $payload): WorkVolumeStatement
    {
        $this->assertScope($actor, $statement, 'budget-estimates.edit');
        $this->assertSourceMetadataNotProvided($payload);
        if (trim((string) ($payload['change_reason'] ?? '')) === '') {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.change_reason_required'), 422);
        }
        return DB::transaction(function () use ($statement, $payload): WorkVolumeStatement {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $statement = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if (! empty($payload['operation_key'])) {
                $replayed = WorkVolumeStatement::query()->where('organization_id', $statement->organization_id)
                    ->where('project_id', $statement->project_id)->where('operation_key', $payload['operation_key'])->first();
                if ($replayed !== null) {
                    if ($replayed->operation_hash !== $this->operationHash($payload, (int) $statement->id)) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
                    }
                    return $replayed->load('lines');
                }
            }
            if ($statement->status !== WorkVolumeStatement::STATUS_APPROVED) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.revision_source_invalid'), 409);
            }
            $lines = $this->validateLines($payload['lines'] ?? [], (int) $statement->organization_id);
            $this->assertEstimateItemsScope($statement->project, (int) $statement->organization_id, $lines);
            $nextVersion = (int) WorkVolumeStatement::query()->where('organization_id', $statement->organization_id)
                ->where('project_id', $statement->project_id)->where('statement_key', $statement->statement_key)->max('version') + 1;
            $revision = WorkVolumeStatement::create([
                'organization_id' => $statement->organization_id, 'project_id' => $statement->project_id,
                'statement_key' => $statement->statement_key, 'version' => $nextVersion,
                'based_on_statement_id' => $statement->id,
                'operation_key' => $payload['operation_key'] ?? null,
                'operation_hash' => ! empty($payload['operation_key']) ? $this->operationHash($payload, (int) $statement->id) : null,
                'name' => $payload['name'] ?? $statement->name, 'status' => WorkVolumeStatement::STATUS_DRAFT,
                'change_reason' => $payload['change_reason'] ?? null,
                'basis_revision' => $payload['basis_revision'] ?? $statement->basis_revision,
            ]);
            $revision->lines()->createMany($lines);
            return $revision->load('lines');
        });
    }

    public function submitForReview(User $actor, WorkVolumeStatement $statement, int $expectedRound): WorkVolumeStatement
    {
        $this->assertScope($actor, $statement, 'budget-estimates.edit');
        return DB::transaction(function () use ($actor, $statement, $expectedRound): WorkVolumeStatement {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $statement = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($statement->status === WorkVolumeStatement::STATUS_REVIEW && $statement->review_round === $expectedRound + 1) {
                return $statement;
            }
            if ($statement->review_round !== $expectedRound) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.review_round_conflict'), 409);
            }
            if ($statement->status !== WorkVolumeStatement::STATUS_DRAFT) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.review_invalid_status'), 409);
            }
            $this->validateLines($statement->lines->toArray());
            $this->recordReviewTransition($statement, $actor, WorkVolumeStatement::STATUS_REVIEW, $expectedRound + 1);
            $statement->forceFill([
                'status' => WorkVolumeStatement::STATUS_REVIEW,
                'review_round' => $expectedRound + 1,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
            ])->save();
            return $statement;
        });
    }

    public function returnForCorrection(User $actor, WorkVolumeStatement $statement, int $expectedRound, string $reason): WorkVolumeStatement
    {
        $this->assertScope($actor, $statement, 'budget-estimates.approve');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.return_reason_required'), 422);
        }
        return DB::transaction(function () use ($actor, $statement, $expectedRound, $reason): WorkVolumeStatement {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $statement = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();
            $history = $statement->review_history ?? [];
            $lastEvent = $history === [] ? null : $history[array_key_last($history)];
            if ($statement->status === WorkVolumeStatement::STATUS_DRAFT && $statement->review_round === $expectedRound
                && ($lastEvent['to_status'] ?? null) === WorkVolumeStatement::STATUS_DRAFT && ($lastEvent['reason'] ?? null) === $reason) {
                return $statement;
            }
            if ($statement->status !== WorkVolumeStatement::STATUS_REVIEW || $statement->review_round !== $expectedRound) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.review_round_conflict'), 409);
            }
            $this->recordReviewTransition($statement, $actor, WorkVolumeStatement::STATUS_DRAFT, $expectedRound, $reason);
            $statement->forceFill(['status' => WorkVolumeStatement::STATUS_DRAFT])->save();
            return $statement;
        });
    }

    public function approve(User $actor, WorkVolumeStatement $statement, int $expectedRound): WorkVolumeStatement
    {
        $this->assertScope($actor, $statement, 'budget-estimates.approve');
        return DB::transaction(function () use ($actor, $statement, $expectedRound): WorkVolumeStatement {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $statement = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($statement->review_round !== $expectedRound) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.review_round_conflict'), 409);
            }
            if ($statement->status === WorkVolumeStatement::STATUS_APPROVED) {
                return $statement;
            }
            if ($statement->status !== WorkVolumeStatement::STATUS_REVIEW) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.approval_invalid_status'), 409);
            }
            $head = WorkVolumeStatement::query()->where('organization_id', $statement->organization_id)
                ->where('project_id', $statement->project_id)->where('statement_key', $statement->statement_key)
                ->where('status', WorkVolumeStatement::STATUS_APPROVED)->first();
            if ((int) $statement->based_on_statement_id !== (int) $head?->id) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.revision_outdated'), 409);
            }
            if (WorkVolumeStatement::query()->where('organization_id', $statement->organization_id)
                ->where('project_id', $statement->project_id)->where('statement_key', $statement->statement_key)
                ->where('version', '>', $statement->version)
                ->whereIn('status', [WorkVolumeStatement::STATUS_APPROVED, WorkVolumeStatement::STATUS_REPLACED])->exists()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.revision_outdated'), 409);
            }
            $this->acceptedAllocations->assertSourcesMapped($statement);
            foreach ($this->acceptedAllocations->protectedLines($statement) as $key => $protection) {
                $line = $statement->lines->firstWhere('line_key', $key);
                if ($line === null || $this->compareDecimal((string) $line->quantity, $protection['quantity']) < 0) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.below_accepted_quantity'), 409);
                }
                foreach ($protection['identities'] as $identity) {
                    if ($this->identityChanged(new WorkVolumeStatementLine($identity), $line)) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.identity_change_after_acceptance'), 409);
                    }
                }
            }
            WorkVolumeStatement::query()->where('organization_id', $statement->organization_id)
                ->where('project_id', $statement->project_id)->where('statement_key', $statement->statement_key)
                ->where('version', '<', $statement->version)->where('status', WorkVolumeStatement::STATUS_APPROVED)
                ->update(['status' => WorkVolumeStatement::STATUS_REPLACED]);
            $this->recordReviewTransition($statement, $actor, WorkVolumeStatement::STATUS_APPROVED, $statement->review_round);
            $statement->forceFill(['status' => WorkVolumeStatement::STATUS_APPROVED, 'approved_at' => now(), 'approved_by_user_id' => $actor->id])->save();
            return $statement->fresh('lines');
        });
    }

    public function previewImport(array $rows): array
    {
        $seen = [];
        $preview = $errors = [];
        foreach ($rows as $index => $row) {
            $line = $this->normalizeLine($row);
            $line['quantity'] = trim((string) ($row['quantity'] ?? ''));
            $preview[] = $line;
            $key = $line['line_key'] ?? '';
            if ($key === '' || isset($seen[$key])) {
                $errors[] = ['row' => $index + 1, 'code' => $key === '' ? 'identity_required' : 'duplicate_identity'];
            }
            $seen[$key] = true;
            if (($line['unit_code'] ?? '') === '' || ($line['place'] ?? []) === []) {
                $errors[] = ['row' => $index + 1, 'code' => 'unit_or_place_unresolved', 'line_key' => $key];
            }
            if (! $this->validQuantity($line['quantity'])) {
                $errors[] = ['row' => $index + 1, 'code' => 'quantity_invalid', 'line_key' => $key];
            }
        }
        return ['rows' => $preview, 'errors' => $errors, 'can_approve' => $errors === []];
    }

    public function updateDraft(User $actor, WorkVolumeStatement $statement, int $expectedDraftVersion, array $payload): array
    {
        $this->assertScope($actor, $statement, 'budget-estimates.edit');
        return DB::transaction(function () use ($actor, $statement, $expectedDraftVersion, $payload): array {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $locked = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();
            if (! is_string($payload['operation_key'] ?? null)) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            $operationKey = trim($payload['operation_key']);
            if ($operationKey === '' || strlen($operationKey) > 128) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            $operationHash = $this->draftEditHash($actor, $locked, $expectedDraftVersion, $payload);
            $replay = DB::table('work_volume_statement_draft_edits')
                ->where('statement_id', $locked->id)->where('actor_id', $actor->id)->where('operation_key', $operationKey)->first();
            if ($replay !== null) {
                if (! hash_equals((string) $replay->operation_hash, $operationHash)) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
                }
                return json_decode((string) $replay->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($locked->status !== WorkVolumeStatement::STATUS_DRAFT) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.draft_edit_invalid_status'), 409);
            }
            if ((int) $locked->draft_version !== $expectedDraftVersion) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.draft_version_conflict'), 409);
            }
            $this->assertSourceMetadataNotProvided($payload);
            if (Validator::make($payload, WorkVolumeStatementDraftEditRequest::payloadRules())->fails()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            $lines = $this->validateLines($payload['lines'] ?? [], (int) $locked->organization_id);
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertEstimateItemsScope($project, (int) $locked->organization_id, $lines);
            $existing = $locked->lines->keyBy('line_key');
            $incomingKeys = array_fill_keys(array_column($lines, 'line_key'), true);
            $removedIds = $existing->reject(static fn (WorkVolumeStatementLine $line): bool => isset($incomingKeys[$line->line_key]))->modelKeys();
            if ($removedIds !== []) {
                if (DB::table('work_volume_accepted_allocations')->whereIn('statement_line_id', $removedIds)->exists()) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.draft_line_allocated'), 409);
                }
                $locked->lines()->whereIn('id', $removedIds)->delete();
            }
            $timestamp = now()->toDateTimeString();
            foreach (array_chunk($lines, 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $line) {
                    $attributes = (new WorkVolumeStatementLine([...$line, 'statement_id' => $locked->id]))->getAttributes();
                    $attributes['created_at'] = $existing->get($line['line_key'])?->getRawOriginal('created_at') ?? $timestamp;
                    $attributes['updated_at'] = $timestamp;
                    $rows[] = $attributes;
                }
                DB::table('work_volume_statement_lines')->upsert($rows, ['statement_id', 'line_key'], [
                    'name', 'unit_code', 'quantity', 'place', 'measurement_formula', 'basis_revision', 'estimate_item_id', 'metadata', 'updated_at',
                ]);
            }
            $locked->forceFill([
                'draft_version' => $expectedDraftVersion + 1,
                'name' => array_key_exists('name', $payload) ? $payload['name'] : $locked->name,
                'basis_revision' => array_key_exists('basis_revision', $payload) ? $payload['basis_revision'] : $locked->basis_revision,
            ])->save();
            $snapshot = $this->draftSnapshot($locked->fresh('lines'));
            DB::table('work_volume_statement_draft_edits')->insert([
                'organization_id' => $locked->organization_id,
                'project_id' => $locked->project_id,
                'statement_id' => $locked->id,
                'actor_id' => $actor->id,
                'expected_draft_version' => $expectedDraftVersion,
                'resulting_draft_version' => $expectedDraftVersion + 1,
                'operation_key' => $operationKey,
                'operation_hash' => $operationHash,
                'result_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $snapshot;
        });
    }

    private function project(User $actor, int $projectId, string $permission): Project
    {
        $project = Project::query()->whereKey($projectId)->firstOrFail();
        $organizationId = (int) $actor->current_organization_id;
        if ($organizationId <= 0
            || ! $actor->belongsToOrganization($organizationId)
            || ! $this->access->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        if (! $this->authorization->can($actor, $permission, ['project_id' => $projectId, 'organization_id' => $organizationId, 'strict_project_scope' => true])) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.forbidden'), 403);
        }
        return $project;
    }

    private function recordReviewTransition(WorkVolumeStatement $statement, User $actor, string $status, int $round, ?string $reason = null): void
    {
        $history = $statement->review_history ?? [];
        $history[] = [
            'from_status' => $statement->status, 'to_status' => $status, 'round' => $round,
            'actor_id' => $actor->id, 'occurred_at' => now()->toISOString(), 'reason' => $reason,
        ];
        $statement->review_history = $history;
    }

    public function assertScope(User $actor, WorkVolumeStatement $statement, string $permission = 'budget-estimates.view'): void
    {
        if ((int) $actor->current_organization_id !== (int) $statement->organization_id) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        $this->project($actor, (int) $statement->project_id, $permission);
    }

    private function validateLines(array $lines, ?int $organizationId = null): array
    {
        if ($lines === [] || Validator::make(['lines' => $lines], WorkVolumeStatementDraftEditRequest::lineRules())->fails()) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
        }
        $normalized = [];
        foreach ($lines as $row) {
            if (! is_array($row)) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            $rawKey = $row['line_key'] ?? null;
            $rawName = $row['name'] ?? null;
            $rawUnit = $row['unit_code'] ?? null;
            $rawQuantity = $row['quantity'] ?? null;
            if (! is_string($rawKey)
                || ! is_string($rawName) || trim($rawName) === '' || ! is_string($rawUnit) || trim($rawUnit) === '' || ! is_string($rawQuantity)) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            $line = $this->normalizeLine($row);
            if ($line['line_key'] === '' || $line['name'] === '' || $line['unit_code'] === '' || $line['place'] === [] || ! $this->validQuantity($line['quantity']) || $this->compareDecimal($line['quantity'], '0') < 0) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
            if (isset($normalized[$line['line_key']])) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.duplicate_line'), 422);
            }
            $normalized[$line['line_key']] = $line;
        }
        $result = array_values($normalized);
        if ($organizationId !== null) {
            $unitCodes = array_values(array_unique(array_column($result, 'unit_code')));
            $knownUnits = MeasurementUnit::query()->where('organization_id', $organizationId)
                ->whereIn('short_name', $unitCodes)->pluck('short_name')->all();
            if (count($knownUnits) !== count($unitCodes)) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.unit_invalid'), 422);
            }
        }
        return $result;
    }

    private function normalizeLine(array $row): array
    {
        $place = is_array($row['place'] ?? null) ? $row['place'] : [];
        $quantity = trim((string) ($row['quantity'] ?? ''));
        if (! $this->validQuantity($quantity)) {
            $quantity = '-1';
        }
        return [
            'line_key' => strtolower(trim((string) ($row['line_key'] ?? ''))),
            'name' => trim((string) ($row['name'] ?? '')),
            'unit_code' => trim((string) ($row['unit_code'] ?? '')),
            'quantity' => $quantity,
            'place' => $place,
            'measurement_formula' => $row['measurement_formula'] ?? null,
            'basis_revision' => $row['basis_revision'] ?? null,
            'estimate_item_id' => $row['estimate_item_id'] ?? null,
            'metadata' => $row['metadata'] ?? null,
        ];
    }

    private function validQuantity(string $quantity): bool
    {
        if (preg_match('/^\d+(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            return false;
        }
        return strlen(ltrim(explode('.', $quantity, 2)[0], '0')) <= 18;
    }

    private function assertSourceMetadataNotProvided(array $payload): void
    {
        if (array_intersect(['source_file_path', 'source_file_hash', 'source_import_id'], array_keys($payload)) !== []) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.source_metadata_readonly'), 422);
        }
    }

    private function compareDecimal(string $left, string $right): int
    {
        return BigDecimal::of($left)->compareTo($right);
    }

    private function operationHash(array $payload, int $sourceStatementId): string
    {
        unset($payload['operation_key']);
        return hash('sha256', json_encode(['source_statement_id' => $sourceStatementId, 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function draftEditHash(User $actor, WorkVolumeStatement $statement, int $expectedVersion, array $payload): string
    {
        return hash('sha256', json_encode([
            'actor_id' => $actor->id, 'statement_id' => $statement->id,
            'expected_draft_version' => $expectedVersion, 'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function draftSnapshot(WorkVolumeStatement $statement): array
    {
        return [
            'statement_id' => $statement->id,
            'draft_version' => (int) $statement->draft_version,
            'name' => $statement->name,
            'basis_revision' => $statement->basis_revision,
            'lines' => $statement->lines->map(static fn (WorkVolumeStatementLine $line): array => $line->toArray())->values()->all(),
        ];
    }

    private function assertEstimateItemsScope(Project $project, int $organizationId, array $lines): void
    {
        $ids = array_values(array_filter(array_map(static fn (array $line): mixed => $line['estimate_item_id'] ?? null, $lines)));
        if ($ids === []) {
            return;
        }
        $items = EstimateItem::query()->whereIn('id', $ids)->whereHas('estimate', fn ($q) => $q->where('organization_id', $organizationId)->where('project_id', $project->id))->get(['id', 'measurement_unit_id']);
        $actual = $items->pluck('id');
        if ($actual->count() !== count(array_unique($ids))) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        foreach ($lines as $line) {
            $item = $items->firstWhere('id', $line['estimate_item_id'] ?? null);
            if ($item?->measurement_unit_id !== null && ! MeasurementUnit::query()->whereKey($item->measurement_unit_id)->where('organization_id', $organizationId)->where('short_name', $line['unit_code'])->exists()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.line_invalid'), 422);
            }
        }
    }

    private function identityChanged(WorkVolumeStatementLine $previous, WorkVolumeStatementLine $current): bool
    {
        return $previous->unit_code !== $current->unit_code
            || (int) $previous->estimate_item_id !== (int) $current->estimate_item_id
            || $previous->basis_revision !== $current->basis_revision
            || $this->canonicalPlace($previous->place) !== $this->canonicalPlace($current->place);
    }

    private function canonicalPlace(mixed $place): string
    {
        if (! is_array($place)) {
            return (string) $place;
        }
        ksort($place);
        return json_encode($place, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
