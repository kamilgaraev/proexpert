<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Backfill;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\CustomerIssue;
use App\Models\CustomerPortalComment;
use App\Models\CustomerRequest;
use App\Models\User;
use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Services\Customer\Reporting\Sla\Models\CustomerWorkflowEvent;
use App\Services\Customer\Reporting\Sla\Services\CustomerWorkflowEventRecorder;
use App\Services\Customer\Reporting\Sla\Services\HistoricalCustomerActorSideResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class CustomerSlaBackfill implements ReportSourceBackfill
{
    public function __construct(
        private HistoricalCustomerActorSideResolver $actorSides,
        private CustomerWorkflowEventRecorder $events,
    ) {
    }

    public function sourceCode(): string
    {
        return 'customer_workflow_events';
    }

    public function sourceSchemaVersion(): string
    {
        return 'customer-sla.v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('customer_sla_backfill_limit_invalid');
        }
        $this->assertCursor($cursor);
        $afterType = (string) ($cursor->position['workflow_type'] ?? 'issue');
        $afterId = (int) ($cursor->position['workflow_id'] ?? 0);
        $keys = collect();
        if ($afterType === 'issue') {
            $keys = $keys->concat(
                CustomerIssue::query()
                    ->where('organization_id', $context->organizationId)
                    ->where('id', '>', $afterId)
                    ->where('created_at', '<=', $context->asOf)
                    ->when(
                        $context->scope->projectIds !== [],
                        fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
                    )
                    ->orderBy('id')
                    ->limit($limit)
                    ->pluck('id')
                    ->map(static fn (mixed $id): array => ['workflow_type' => 'issue', 'workflow_id' => (int) $id]),
            );
        }
        if ($keys->count() < $limit) {
            $requestAfter = $afterType === 'request' ? $afterId : 0;
            $keys = $keys->concat(
                CustomerRequest::query()
                    ->where('organization_id', $context->organizationId)
                    ->where('id', '>', $requestAfter)
                    ->where('created_at', '<=', $context->asOf)
                    ->when(
                        $context->scope->projectIds !== [],
                        fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
                    )
                    ->orderBy('id')
                    ->limit($limit - $keys->count())
                    ->pluck('id')
                    ->map(static fn (mixed $id): array => ['workflow_type' => 'request', 'workflow_id' => (int) $id]),
            );
        }
        $sourceKeys = $keys->values()->all();
        $last = $sourceKeys === [] ? [
            'workflow_type' => $afterType,
            'workflow_id' => $afterId,
        ] : $sourceKeys[array_key_last($sourceKeys)];
        $to = new ReportSourceBackfillCursor($last);
        $final = count($sourceKeys) < $limit;

        return new ReportSourceBackfillBatch(
            $cursor,
            $to,
            $sourceKeys,
            $final,
            hash('sha256', CanonicalJson::encode([
                'as_of' => $context->asOf->toISOString(),
                'final' => $final,
                'from' => $cursor->position,
                'organization_id' => $context->organizationId,
                'source_keys' => $sourceKeys,
                'source_watermark' => $context->sourceWatermark,
                'to' => $to->position,
            ])),
        );
    }

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult {
        $this->assertBatch($context, $batch);
        $projection = [];
        $eligible = 0;
        $projected = 0;
        $unknown = 0;
        foreach ($batch->sourceKeys as $key) {
            $workflowType = (string) ($key['workflow_type'] ?? '');
            $workflowId = (int) ($key['workflow_id'] ?? 0);
            $workflow = (match ($workflowType) {
                'issue' => CustomerIssue::query(),
                'request' => CustomerRequest::query(),
                default => null,
            })?->where('organization_id', $context->organizationId)
                ->where('created_at', '<=', $context->asOf)
                ->when(
                    $context->scope->projectIds !== [],
                    fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
                )
                ->whereKey($workflowId)
                ->first();
            if (!$workflow instanceof Model) {
                $eligible++;
                $unknown++;
                continue;
            }
            $facts = $this->facts($workflowType, $workflow, $context->asOf);
            $eligible += count($facts);
            $resolvedFacts = [];
            foreach ($facts as $fact) {
                $actor = User::query()->find($fact['actor_id']);
                if (!$actor instanceof User) {
                    $resolvedFacts = [];
                    break;
                }
                $customerOrganizationId = $this->actorSides->customerOrganizationId(
                    $workflow->getAttribute('project_id') === null
                        ? null
                        : (int) $workflow->getAttribute('project_id'),
                    $fact['occurred_at'],
                );
                $actorSide = $this->actorSides->resolve(
                    $context->organizationId,
                    $customerOrganizationId,
                    (int) $actor->id,
                    $fact['occurred_at'],
                );
                if (
                    $actorSide === CustomerActorSide::UNKNOWN
                    || $customerOrganizationId === null
                ) {
                    $resolvedFacts = [];
                    break;
                }
                $resolvedFacts[] = [
                    ...$fact,
                    'actor' => $actor,
                    'actor_side' => $actorSide,
                    'customer_organization_id' => $customerOrganizationId,
                ];
            }
            if (count($resolvedFacts) !== count($facts)) {
                $unknown += count($facts);
                continue;
            }
            try {
                $workflowProjection = DB::transaction(function () use (
                    $context,
                    $workflowType,
                    $workflowId,
                    $workflow,
                    $resolvedFacts,
                ): array {
                    $result = [];
                    foreach ($resolvedFacts as $fact) {
                        $existing = CustomerWorkflowEvent::query()
                            ->where('organization_id', $context->organizationId)
                            ->where('workflow_type', $workflowType)
                            ->where('workflow_id', $workflowId)
                            ->where('event_type', $fact['event_type']->value)
                            ->where('actor_id', (int) $fact['actor']->id)
                            ->where('occurred_at', $fact['occurred_at'])
                            ->first();
                        $event = $existing ?? $this->record(
                            $workflowType,
                            $workflow,
                            $fact['event_type'],
                            $fact['actor'],
                            $fact['occurred_at'],
                            $fact['actor_side'],
                            $fact['customer_organization_id'],
                            $fact['prior_status'],
                            $fact['current_status'],
                        );
                        $result[] = [
                            'event_id' => (string) $event->event_id,
                            'evidence_hash' => (string) $event->evidence_hash,
                        ];
                    }

                    return $result;
                }, 3);
                $projection = [...$projection, ...$workflowProjection];
                $projected += count($facts);
            } catch (Throwable) {
                $unknown += count($facts);
            }
        }
        $gap = max(0, $eligible - $projected - $unknown);

        return new ReportSourceBackfillResult(
            $batch->to,
            $eligible,
            $projected,
            $gap,
            $unknown,
            hash('sha256', CanonicalJson::encode($projection)),
            $batch->final,
        );
    }

    private function facts(string $workflowType, Model $workflow, CarbonImmutable $asOf): array
    {
        $rawFacts = [[
            'actor_id' => (int) $workflow->getAttribute('author_user_id'),
            'kind' => 'opened',
            'occurred_at' => CarbonImmutable::instance($workflow->getAttribute('created_at')),
            'status' => 'new',
        ]];
        $commentableType = $workflowType === 'issue' ? CustomerIssue::class : CustomerRequest::class;
        foreach (
            CustomerPortalComment::query()
                ->where('commentable_type', $commentableType)
                ->where('commentable_id', (int) $workflow->getKey())
                ->where('created_at', '<=', $asOf)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get() as $comment
        ) {
            $rawFacts[] = [
                'actor_id' => (int) $comment->author_user_id,
                'kind' => 'comment',
                'occurred_at' => CarbonImmutable::instance($comment->created_at),
                'status' => null,
            ];
        }
        $metadata = $workflow->getAttribute('metadata');
        $history = is_array($metadata) && is_array($metadata['history'] ?? null)
            ? $metadata['history']
            : [];
        $resolutionTimestamps = [];
        foreach ($history as $entry) {
            if (
                !is_array($entry)
                || ($entry['type'] ?? null) !== 'status_changed'
                || !is_int($entry['author_id'] ?? null)
                || !is_string($entry['created_at'] ?? null)
                || !is_string($entry['status'] ?? null)
            ) {
                continue;
            }
            $occurredAt = CarbonImmutable::parse($entry['created_at']);
            $rawFacts[] = [
                'actor_id' => $entry['author_id'],
                'kind' => 'status',
                'occurred_at' => $occurredAt,
                'status' => $entry['status'],
            ];
            if (in_array($entry['status'], $this->terminalStatuses($workflowType), true)) {
                $resolutionTimestamps[$occurredAt->toISOString()] = true;
            }
        }
        $resolvedAt = $workflow->getAttribute('resolved_at');
        $resolvedBy = $workflow->getAttribute('resolved_by_user_id');
        if (
            $resolvedAt !== null
            && $resolvedBy !== null
            && CarbonImmutable::instance($resolvedAt) <= $asOf
            && !isset($resolutionTimestamps[CarbonImmutable::instance($resolvedAt)->toISOString()])
        ) {
            $rawFacts[] = [
                'actor_id' => (int) $resolvedBy,
                'kind' => 'status',
                'occurred_at' => CarbonImmutable::instance($resolvedAt),
                'status' => (string) $workflow->getAttribute('status'),
            ];
        }
        usort(
            $rawFacts,
            static fn (array $left, array $right): int =>
                $left['occurred_at'] <=> $right['occurred_at']
                ?: (['opened' => 0, 'comment' => 1, 'status' => 2][$left['kind']] ?? 3)
                    <=> (['opened' => 0, 'comment' => 1, 'status' => 2][$right['kind']] ?? 3),
        );
        $facts = [];
        $currentStatus = null;
        foreach ($rawFacts as $rawFact) {
            if ($rawFact['occurred_at'] > $asOf) {
                continue;
            }
            $priorStatus = $currentStatus;
            $eventType = match ($rawFact['kind']) {
                'opened' => CustomerWorkflowEventType::OPENED,
                'comment' => CustomerWorkflowEventType::COMMENTED,
                'status' => match (true) {
                    in_array($rawFact['status'], $this->terminalStatuses($workflowType), true) =>
                        CustomerWorkflowEventType::RESOLVED,
                    in_array($currentStatus, $this->terminalStatuses($workflowType), true) =>
                        CustomerWorkflowEventType::REOPENED,
                    default => CustomerWorkflowEventType::STATUS_CHANGED,
                },
                default => throw new InvalidArgumentException('customer_sla_backfill_fact_invalid'),
            };
            $currentStatus = match ($rawFact['kind']) {
                'opened' => 'new',
                'comment' => $currentStatus === 'new' ? 'in_progress' : $currentStatus,
                'status' => $rawFact['status'],
                default => null,
            };
            if (!is_string($currentStatus) || trim($currentStatus) === '') {
                throw new InvalidArgumentException('customer_sla_backfill_status_invalid');
            }
            $facts[] = [
                'actor_id' => $rawFact['actor_id'],
                'prior_status' => $priorStatus,
                'current_status' => $currentStatus,
                'event_type' => $eventType,
                'occurred_at' => $rawFact['occurred_at'],
            ];
        }

        return $facts;
    }

    private function record(
        string $workflowType,
        Model $workflow,
        CustomerWorkflowEventType $eventType,
        User $actor,
        CarbonImmutable $occurredAt,
        CustomerActorSide $actorSide,
        ?int $customerOrganizationId,
        ?string $priorStatus,
        string $currentStatus,
    ): CustomerWorkflowEvent {
        if ($workflowType === 'issue' && $workflow instanceof CustomerIssue) {
            return $this->events->recordHistoricalIssue(
                $workflow,
                $eventType,
                $actor,
                $occurredAt,
                $actorSide,
                $customerOrganizationId,
                $priorStatus,
                $currentStatus,
            );
        }
        if ($workflowType === 'request' && $workflow instanceof CustomerRequest) {
            return $this->events->recordHistoricalRequest(
                $workflow,
                $eventType,
                $actor,
                $occurredAt,
                $actorSide,
                $customerOrganizationId,
                $priorStatus,
                $currentStatus,
            );
        }

        throw new InvalidArgumentException('customer_sla_backfill_workflow_invalid');
    }

    private function terminalStatuses(string $workflowType): array
    {
        return $workflowType === 'issue'
            ? ['resolved', 'rejected']
            : ['completed', 'rejected'];
    }

    private function assertBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): void {
        foreach ($batch->sourceKeys as $sourceKey) {
            if (
                !is_array($sourceKey)
                || array_keys($sourceKey) !== ['workflow_type', 'workflow_id']
                || !in_array($sourceKey['workflow_type'], ['issue', 'request'], true)
                || !is_int($sourceKey['workflow_id'])
                || $sourceKey['workflow_id'] < 1
            ) {
                throw new InvalidArgumentException('customer_sla_backfill_batch_invalid');
            }
        }
        $expected = hash('sha256', CanonicalJson::encode([
            'as_of' => $context->asOf->toISOString(),
            'final' => $batch->final,
            'from' => $batch->from->position,
            'organization_id' => $context->organizationId,
            'source_keys' => $batch->sourceKeys,
            'source_watermark' => $context->sourceWatermark,
            'to' => $batch->to->position,
        ]));
        $last = $batch->sourceKeys === []
            ? $batch->from->position
            : $batch->sourceKeys[array_key_last($batch->sourceKeys)];
        $previous = [
            'workflow_type' => (string) ($batch->from->position['workflow_type'] ?? 'issue'),
            'workflow_id' => (int) ($batch->from->position['workflow_id'] ?? 0),
        ];
        $ordered = true;
        foreach ($batch->sourceKeys as $sourceKey) {
            if (!$this->after($sourceKey, $previous)) {
                $ordered = false;
                break;
            }
            $previous = $sourceKey;
        }
        if (
            !hash_equals($expected, $batch->inputHash)
            || $batch->to->position !== $last
            || !$ordered
            || count($batch->sourceKeys) !== count(array_unique(
                array_map(CanonicalJson::encode(...), $batch->sourceKeys),
            ))
        ) {
            throw new InvalidArgumentException('customer_sla_backfill_batch_invalid');
        }
    }

    private function assertCursor(ReportSourceBackfillCursor $cursor): void
    {
        if ($cursor->position === []) {
            return;
        }
        if (
            array_keys($cursor->position) !== ['workflow_type', 'workflow_id']
            || !in_array($cursor->position['workflow_type'], ['issue', 'request'], true)
            || !is_int($cursor->position['workflow_id'])
            || $cursor->position['workflow_id'] < 0
        ) {
            throw new InvalidArgumentException('customer_sla_backfill_cursor_invalid');
        }
    }

    private function after(array $candidate, array $previous): bool
    {
        $rank = ['issue' => 0, 'request' => 1];
        $candidateRank = $rank[$candidate['workflow_type']] ?? -1;
        $previousRank = $rank[$previous['workflow_type']] ?? -1;

        return $candidateRank > $previousRank
            || ($candidateRank === $previousRank
                && $candidate['workflow_id'] > $previous['workflow_id']);
    }
}
