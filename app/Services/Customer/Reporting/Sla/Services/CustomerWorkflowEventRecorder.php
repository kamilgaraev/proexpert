<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\CustomerIssue;
use App\Models\CustomerRequest;
use App\Models\User;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Services\Customer\Reporting\Sla\Models\CustomerWorkflowEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CustomerWorkflowEventRecorder
{
    public function __construct(private CustomerActorSideResolver $actorSides)
    {
    }

    public function recordIssue(
        CustomerIssue $issue,
        CustomerWorkflowEventType $type,
        User $actor,
        CarbonImmutable $occurredAt,
    ): CustomerWorkflowEvent {
        return $this->record('issue', $issue, $type, $actor, $occurredAt);
    }

    public function recordRequest(
        CustomerRequest $request,
        CustomerWorkflowEventType $type,
        User $actor,
        CarbonImmutable $occurredAt,
    ): CustomerWorkflowEvent {
        return $this->record('request', $request, $type, $actor, $occurredAt);
    }

    private function record(
        string $workflowType,
        Model $workflow,
        CustomerWorkflowEventType $type,
        User $actor,
        CarbonImmutable $occurredAt,
    ): CustomerWorkflowEvent {
        if (!$workflow->exists || !$actor->exists) {
            throw new InvalidArgumentException('customer_workflow_event_invalid');
        }

        return DB::transaction(function () use (
            $workflowType,
            $workflow,
            $type,
            $actor,
            $occurredAt,
        ): CustomerWorkflowEvent {
            $existing = CustomerWorkflowEvent::query()
                ->where('organization_id', (int) $workflow->getAttribute('organization_id'))
                ->where('workflow_type', $workflowType)
                ->where('workflow_id', (int) $workflow->getKey())
                ->where('event_type', $type->value)
                ->where('actor_id', (int) $actor->id)
                ->where('occurred_at', $occurredAt)
                ->first();
            if ($existing instanceof CustomerWorkflowEvent) {
                return $existing;
            }

            $last = CustomerWorkflowEvent::query()
                ->where('organization_id', (int) $workflow->getAttribute('organization_id'))
                ->where('workflow_type', $workflowType)
                ->where('workflow_id', (int) $workflow->getKey())
                ->lockForUpdate()
                ->orderByDesc('source_version')
                ->first();
            if (
                ($last !== null && $occurredAt < $last->occurred_at)
                || ($type === CustomerWorkflowEventType::OPENED && $last !== null)
                || ($type !== CustomerWorkflowEventType::OPENED && !$this->openedEventExists($workflowType, $workflow))
            ) {
                throw new InvalidArgumentException('customer_workflow_event_sequence_invalid');
            }
            $sourceVersion = $last === null ? 1 : ((int) $last->source_version) + 1;
            $customerOrganizationId = $this->customerOrganizationId($workflow);
            $actorSide = $this->actorSides->resolve(
                (int) $workflow->getAttribute('organization_id'),
                $customerOrganizationId,
                $this->actorOrganizationIds($actor),
            );
            $priorStatus = $last?->current_status;
            $currentStatus = (string) $workflow->getAttribute('status');
            $metadata = $workflow->getAttribute('metadata');
            $priority = is_array($metadata) && is_string($metadata['priority'] ?? null)
                ? $metadata['priority']
                : null;
            $ownerId = is_array($metadata) && is_int($metadata['owner_id'] ?? null)
                ? $metadata['owner_id']
                : null;
            $evidence = [
                'source_updated_at' => $workflow->getAttribute('updated_at')?->toISOString(),
                'status' => $currentStatus,
            ];
            $identity = [
                'actor_id' => (int) $actor->id,
                'actor_side' => $actorSide->value,
                'event_type' => $type->value,
                'occurred_at' => $occurredAt->toISOString(),
                'organization_id' => (int) $workflow->getAttribute('organization_id'),
                'source_version' => $sourceVersion,
                'workflow_id' => (int) $workflow->getKey(),
                'workflow_type' => $workflowType,
            ];

            return CustomerWorkflowEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'organization_id' => (int) $workflow->getAttribute('organization_id'),
                'customer_organization_id' => $customerOrganizationId,
                'project_id' => $workflow->getAttribute('project_id'),
                'workflow_type' => $workflowType,
                'workflow_id' => (int) $workflow->getKey(),
                'source_version' => $sourceVersion,
                'event_type' => $type,
                'prior_status' => $priorStatus,
                'current_status' => $currentStatus,
                'actor_side' => $actorSide,
                'actor_id' => (int) $actor->id,
                'priority' => $priority,
                'owner_id' => $ownerId,
                'occurred_at' => $occurredAt,
                'idempotency_key_hash' => hash('sha256', CanonicalJson::encode($identity)),
                'evidence_hash' => hash('sha256', CanonicalJson::encode([$identity, $evidence])),
                'evidence' => $evidence,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        }, 3);
    }

    private function customerOrganizationId(Model $workflow): ?int
    {
        $projectId = $workflow->getAttribute('project_id');
        if ($projectId === null) {
            return null;
        }

        $customer = DB::table('project_organization')
            ->where('project_id', (int) $projectId)
            ->where('is_active', true)
            ->where(static function ($builder): void {
                $builder
                    ->where('role_new', 'customer')
                    ->orWhere(static function ($fallback): void {
                        $fallback->whereNull('role_new')->where('role', 'customer');
                    });
            })
            ->orderByDesc('id')
            ->value('organization_id');

        return $customer === null ? null : (int) $customer;
    }

    private function actorOrganizationIds(User $actor): array
    {
        return DB::table('organization_user')
            ->where('user_id', (int) $actor->id)
            ->where('is_active', true)
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function openedEventExists(string $workflowType, Model $workflow): bool
    {
        return CustomerWorkflowEvent::query()
            ->where('organization_id', (int) $workflow->getAttribute('organization_id'))
            ->where('workflow_type', $workflowType)
            ->where('workflow_id', (int) $workflow->getKey())
            ->where('event_type', CustomerWorkflowEventType::OPENED->value)
            ->exists();
    }
}
