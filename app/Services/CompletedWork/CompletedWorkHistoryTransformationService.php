<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\CompletedWorkHistoryTransformation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CompletedWorkHistoryTransformationService
{
    public function __construct(
        private readonly CompletedWorkReconciliationQuery $query,
        private readonly CompletedWorkReconciliationService $classifier,
        private readonly CompletedWorkScopeResolver $scopeResolver,
    ) {}

    public function transformProject(
        int $organizationId,
        int $projectId,
        ?User $actor,
        bool $dryRun = false,
        ?int $afterId = null,
        int $batchSize = CompletedWorkReconciliationQuery::DEFAULT_BATCH_SIZE,
        bool $allPages = false,
    ): array {
        $records = [];
        $meta = [
            'applied' => 0,
            'recorded' => 0,
            'skipped_manual' => 0,
            'already_done' => 0,
            'dry_run' => $dryRun,
        ];
        $cursor = $afterId;

        do {
            $page = $this->query->collect($organizationId, $projectId, $cursor, $batchSize);
            foreach ($page['records'] as $report) {
                $item = $dryRun
                    ? $this->preview($report)
                    : $this->applyAuto($report, $actor);
                $records[] = $item;
                $action = $item['auto_action'];
                if ($action === CompletedWorkHistoryTransformationRules::ACTION_APPLY) {
                    $meta['applied']++;
                } elseif ($action === CompletedWorkHistoryTransformationRules::ACTION_RECORD_CANONICAL) {
                    $meta['recorded']++;
                } elseif ($action === CompletedWorkHistoryTransformationRules::ACTION_ALREADY_DONE) {
                    $meta['already_done']++;
                } else {
                    $meta['skipped_manual']++;
                }
            }
            $cursor = $page['meta']['next_cursor'];
        } while ($allPages && $cursor !== null);

        $meta['returned'] = count($records);
        $meta['stop_on_discrepancy'] = $meta['skipped_manual'] > 0;
        $meta['next_cursor'] = $allPages ? null : $cursor;
        $meta['organization_id'] = $organizationId;
        $meta['project_id'] = $projectId;

        return ['records' => $records, 'meta' => $meta];
    }

    public function decide(
        CompletedWork $work,
        User $actor,
        ProjectContext $context,
        array $input,
    ): CompletedWorkHistoryTransformation {
        $this->scopeResolver->assertCorrection($work, $actor, $context);

        try {
            return DB::transaction(function () use ($work, $actor, $input): CompletedWorkHistoryTransformation {
                $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->firstOrFail();
                $report = $this->query->report(
                    (int) $locked->organization_id,
                    (int) $locked->project_id,
                    (int) $locked->id,
                );
                $existing = CompletedWorkHistoryTransformation::query()
                    ->where('completed_work_id', $locked->id)
                    ->lockForUpdate()
                    ->first();
                $canonical = CompletedWorkHistoryTransformationRules::canonicalFromDecision(
                    $report,
                    (string) $input['source'],
                    $input['canonical_quantity'] ?? null,
                );
                if ($canonical === null) {
                    throw new BusinessLogicException(trans_message('completed_work.history_decision_invalid'), 422);
                }
                $mutate = ! (bool) $report['protected_history'];
                $payloadHash = $this->hash([
                    'source' => CompletedWorkHistoryTransformationRules::SOURCE_MANUAL,
                    'decision_source' => $input['source'],
                    'canonical_quantity' => $canonical,
                    'reason' => (string) $input['reason'],
                ]);
                if ($existing) {
                    if ($existing->operation_key === (string) $input['operation_key'] && $existing->payload_hash === $payloadHash) {
                        return $existing;
                    }
                    throw new BusinessLogicException(
                        $existing->operation_key === (string) $input['operation_key']
                            ? trans_message('completed_work.history_decision_conflict')
                            : trans_message('completed_work.history_already_transformed'),
                        409,
                    );
                }
                if (CompletedWorkRevisionToken::forWork($locked) !== (string) $input['expected_version']) {
                    throw new BusinessLogicException(trans_message('completed_work.history_stale'), 409);
                }

                if ($mutate) {
                    $locked->forceFill([
                        'quantity' => $canonical,
                        'completed_quantity' => $canonical,
                    ])->saveQuietly();
                }

                return CompletedWorkHistoryTransformation::query()->create([
                    'completed_work_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                    'project_id' => $locked->project_id,
                    'actor_id' => $actor->id,
                    'source' => CompletedWorkHistoryTransformationRules::SOURCE_MANUAL,
                    'rule' => CompletedWorkHistoryTransformationRules::RULE_MANUAL,
                    'outcome' => $mutate
                        ? CompletedWorkHistoryTransformationRules::OUTCOME_APPLIED
                        : CompletedWorkHistoryTransformationRules::OUTCOME_RECORDED,
                    'fields_mutated' => $mutate,
                    'operation_key' => (string) $input['operation_key'],
                    'payload_hash' => $payloadHash,
                    'reason' => (string) $input['reason'],
                    'original_quantity' => $this->storedDecimal($report['source']['quantity'] ?? null),
                    'original_completed_quantity' => $this->storedDecimal($report['source']['completed_quantity'] ?? null),
                    'canonical_quantity' => $canonical,
                    'protocol' => $this->protocol($report, $actor, CompletedWorkHistoryTransformationRules::RULE_MANUAL, [
                        'decision_source' => $input['source'],
                        'fields_mutated' => $mutate,
                    ]),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = CompletedWorkHistoryTransformation::query()->where('completed_work_id', $work->id)->first();
            if ($existing && $existing->operation_key === (string) $input['operation_key']) {
                return $existing;
            }
            throw new BusinessLogicException(trans_message('completed_work.history_already_transformed'), 409);
        }
    }

    private function preview(array $report): array
    {
        $plan = CompletedWorkHistoryTransformationRules::plan($report, $report['transformation'] ?? null);

        return [
            'work_id' => $report['work_id'],
            'category' => $plan['category'],
            'auto_action' => $plan['auto_action'],
            'rule' => $plan['rule'],
            'canonical_quantity' => $plan['canonical_quantity'],
            'fields_mutated' => false,
            'transformation_id' => $report['transformation']['id'] ?? null,
        ];
    }

    private function applyAuto(array $report, ?User $actor): array
    {
        try {
            return DB::transaction(function () use ($report, $actor): array {
                $locked = CompletedWork::query()->whereKey($report['work_id'])->lockForUpdate()->firstOrFail();
                $existing = CompletedWorkHistoryTransformation::query()
                    ->where('completed_work_id', $locked->id)
                    ->lockForUpdate()
                    ->first();
                $fresh = $this->classifyLocked($locked, $report);
                $plan = CompletedWorkHistoryTransformationRules::plan(
                    $fresh,
                    $existing?->toReport(),
                );
                if ($plan['auto_action'] === CompletedWorkHistoryTransformationRules::ACTION_ALREADY_DONE) {
                    return $this->item($fresh['work_id'], $plan, $existing, false);
                }
                if ($plan['auto_action'] === CompletedWorkHistoryTransformationRules::ACTION_SKIP_MANUAL) {
                    return $this->item($fresh['work_id'], $plan, null, false);
                }

                $mutated = false;
                if ($plan['mutate'] && is_string($plan['canonical_quantity'])) {
                    $locked->forceFill([
                        'quantity' => $plan['canonical_quantity'],
                        'completed_quantity' => $plan['canonical_quantity'],
                    ])->saveQuietly();
                    $mutated = true;
                }

                $protocol = CompletedWorkHistoryTransformation::query()->create([
                    'completed_work_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                    'project_id' => $locked->project_id,
                    'actor_id' => $actor?->id,
                    'source' => CompletedWorkHistoryTransformationRules::SOURCE_AUTO,
                    'rule' => $plan['rule'],
                    'outcome' => $mutated
                        ? CompletedWorkHistoryTransformationRules::OUTCOME_APPLIED
                        : CompletedWorkHistoryTransformationRules::OUTCOME_ALREADY_CANONICAL,
                    'fields_mutated' => $mutated,
                    'operation_key' => CompletedWorkHistoryTransformationRules::AUTO_OPERATION_KEY,
                    'payload_hash' => $this->hash([
                        'source' => CompletedWorkHistoryTransformationRules::SOURCE_AUTO,
                        'rule' => $plan['rule'],
                        'canonical_quantity' => $plan['canonical_quantity'],
                        'original_quantity' => $fresh['source']['quantity'] ?? null,
                        'original_completed_quantity' => $fresh['source']['completed_quantity'] ?? null,
                    ]),
                    'reason' => null,
                    'original_quantity' => $this->storedDecimal($fresh['source']['quantity'] ?? null),
                    'original_completed_quantity' => $this->storedDecimal($fresh['source']['completed_quantity'] ?? null),
                    'canonical_quantity' => $plan['canonical_quantity'],
                    'protocol' => $this->protocol($fresh, $actor, (string) $plan['rule'], [
                        'fields_mutated' => $mutated,
                    ]),
                ]);

                return $this->item($fresh['work_id'], $plan, $protocol, $mutated);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = CompletedWorkHistoryTransformation::query()->where('completed_work_id', $report['work_id'])->first();
            $plan = CompletedWorkHistoryTransformationRules::plan($report, $existing?->toReport());

            return $this->item((int) $report['work_id'], $plan, $existing, false);
        }
    }

    private function classifyLocked(CompletedWork $locked, array $report): array
    {
        $source = [
            'id' => (int) $locked->id,
            'organization_id' => (int) $locked->organization_id,
            'project_id' => (int) $locked->project_id,
            'quantity' => $locked->quantity,
            'completed_quantity' => $locked->completed_quantity,
            'status' => $locked->status,
            'journal_entry_id' => $locked->journal_entry_id,
            'journal_work_volume_id' => $locked->journal_work_volume_id,
            'total_amount' => $locked->total_amount,
            'acts' => $report['acts'] ?? [],
        ];
        $fresh = $this->classifier->analyze([$source], (int) $locked->organization_id, (int) $locked->project_id)[0];
        $fresh['protected_history'] = (bool) ($fresh['protected_history'] || ($report['protected_history'] ?? false));
        if (in_array('duplicate_journal_volume', $report['issues'] ?? [], true)
            && ! in_array('duplicate_journal_volume', $fresh['issues'], true)
        ) {
            $fresh['issues'][] = 'duplicate_journal_volume';
            $fresh['duplicate_work_ids'] = $report['duplicate_work_ids'] ?? [];
            $fresh['requires_manual_review'] = true;
            $fresh['resolved_quantity'] = null;
        }
        $fresh['acceptances'] = $report['acceptances'] ?? [];
        $fresh['work_id'] = (int) $locked->id;

        return $fresh;
    }

    private function item(int $workId, array $plan, ?CompletedWorkHistoryTransformation $protocol, bool $mutated): array
    {
        return [
            'work_id' => $workId,
            'category' => $plan['category'],
            'auto_action' => $plan['auto_action'],
            'rule' => $plan['rule'],
            'canonical_quantity' => $plan['canonical_quantity'],
            'fields_mutated' => $mutated,
            'transformation_id' => $protocol?->id,
        ];
    }

    private function storedDecimal(mixed $value): ?string
    {
        return CompletedWorkReconciliationService::normalizeQuantity(
            is_string($value) || is_int($value) ? $value : null
        );
    }

    private function protocol(array $report, ?User $actor, string $rule, array $extra): array
    {
        return [
            'rule_version' => 't27-v1',
            'rule' => $rule,
            'actor_id' => $actor?->id,
            'issues' => $report['issues'] ?? [],
            'protected_history' => (bool) ($report['protected_history'] ?? false),
            'source' => $report['source'] ?? [],
            'signed_act_ids' => array_values(array_map(
                static fn (array $act): int => (int) $act['id'],
                array_filter($report['acts'] ?? [], static fn (array $act): bool => ($act['is_approved'] ?? false)
                    || in_array($act['status'] ?? null, ['approved', 'signed'], true)),
            )),
            ...$extra,
        ];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
