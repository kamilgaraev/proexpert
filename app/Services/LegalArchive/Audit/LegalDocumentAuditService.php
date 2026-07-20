<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Models\User;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalDocumentAuditService implements LegalDocumentAudit
{
    public function __construct(
        private ImmutableAuditRecorder $recorder,
        private LegalDocumentOutbox $outbox,
        private ConnectionInterface $connection,
    ) {}

    public function record(
        string $event,
        LegalArchiveDocument $document,
        User $actor,
        array $context = [],
    ): void {
        $this->recordLegalDocument($event, $document, (int) $actor->id, $context, [
            'name' => $actor->name,
        ]);
    }

    public function recordForActorId(
        string $event,
        LegalArchiveDocument $document,
        ?int $actorId,
        array $context = [],
    ): void {
        $this->recordLegalDocument($event, $document, $actorId, $context);
    }

    public function recordContractForActorId(
        string $event,
        Contract $contract,
        ?int $actorId,
        array $context = [],
    ): void {
        $organizationId = (int) $contract->organization_id;
        $contractId = (string) $contract->getKey();
        $this->assertIdentity($organizationId, $contractId);

        $this->connection->transaction(function () use ($organizationId, $contractId, $event, $contract, $actorId, $context): void {
            $auditEvent = $this->recorder->record(new ImmutableAuditEventData(
                organizationId: $organizationId,
                domain: 'contracts',
                eventType: "contract.{$event}",
                action: $event,
                source: 'contracts',
                projectId: $contract->project_id === null ? null : (int) $contract->project_id,
                actorType: $actorId === null ? 'system' : 'user',
                actorUserId: $actorId,
                sourceModel: Contract::class,
                sourceTable: $contract->getTable(),
                sourceEventId: $this->sourceEventId($context, $organizationId, 'contracts', 'contracts', 'contract', $contractId),
                idempotencyKey: $this->idempotencyKey($context),
                subjectType: 'contract',
                subjectId: $contractId,
                subjectLabel: (string) ($contract->number ?? ''),
                beforeState: $this->arrayContext($context, 'before'),
                afterState: $this->arrayContext($context, 'after'),
                diff: $this->arrayContext($context, 'diff'),
                domainContext: $this->businessContext($context),
                chainScope: "organization:{$organizationId}:contract:{$contractId}",
            ));

            $this->enqueue($auditEvent, 'contract', $contractId);
        }, 3);
    }

    private function recordLegalDocument(
        string $event,
        LegalArchiveDocument $document,
        ?int $actorId,
        array $context,
        array $actorSnapshot = [],
    ): void {
        $organizationId = (int) $document->organization_id;
        $documentId = (string) $document->getKey();
        $this->assertIdentity($organizationId, $documentId);

        $this->connection->transaction(function () use ($organizationId, $documentId, $event, $document, $actorId, $actorSnapshot, $context): void {
            $auditEvent = $this->recorder->record(new ImmutableAuditEventData(
                organizationId: $organizationId,
                domain: 'legal_archive',
                eventType: "legal_document.{$event}",
                action: $event,
                source: 'legal_archive',
                projectId: $document->primary_project_id === null ? null : (int) $document->primary_project_id,
                actorType: $actorId === null ? 'system' : 'user',
                actorUserId: $actorId,
                actorSnapshot: $actorSnapshot,
                sourceModel: LegalArchiveDocument::class,
                sourceTable: $document->getTable(),
                sourceEventId: $this->sourceEventId($context, $organizationId, 'legal_archive', 'legal_archive', 'legal_document', $documentId),
                idempotencyKey: $this->idempotencyKey($context),
                subjectType: 'legal_document',
                subjectId: $documentId,
                subjectLabel: (string) ($document->title ?? ''),
                beforeState: $this->arrayContext($context, 'before'),
                afterState: $this->arrayContext($context, 'after'),
                diff: $this->arrayContext($context, 'diff'),
                domainContext: $this->businessContext($context),
                chainScope: "organization:{$organizationId}:legal_document:{$documentId}",
            ));

            $this->enqueue($auditEvent, 'legal_document', $documentId);
        }, 3);
    }

    private function enqueue(ImmutableAuditEvent $event, string $aggregateType, string $aggregateId): void
    {
        $this->outbox->enqueue(
            (string) $event->event_type,
            $aggregateType,
            $aggregateId,
            [
                'organization_id' => (int) $event->organization_id,
                'audit_event_id' => (string) $event->id,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event' => (string) $event->event_type,
                'occurred_at' => $event->occurred_at,
            ],
            'audit:'.(string) $event->id,
        );
    }

    private function assertIdentity(int $organizationId, string $aggregateId): void
    {
        if ($organizationId < 1 || $aggregateId === '') {
            throw new DomainException('legal_document_audit_identity_required');
        }
    }

    private function sourceEventId(
        array $context,
        int $organizationId,
        string $domain,
        string $source,
        string $aggregateType,
        string $aggregateId,
    ): ?string {
        $value = $context['source_event_id'] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }
        $rawValue = $value;
        $legacyNamespaced = "{$aggregateType}:{$aggregateId}:{$rawValue}";
        $value = LegalDocumentSourceEventId::canonical("{$aggregateType}:{$aggregateId}", $rawValue);
        $lookup = [$value];
        if (strlen($rawValue) <= LegalDocumentSourceEventId::MAX_LENGTH) {
            $lookup[] = $rawValue;
        }
        if (strlen($legacyNamespaced) <= LegalDocumentSourceEventId::MAX_LENGTH) {
            $lookup[] = $legacyNamespaced;
        }
        $existing = $this->connection->table('immutable_audit_events')
            ->where('organization_id', $organizationId)
            ->where('domain', $domain)
            ->where('source', $source)
            ->whereIn('source_event_id', array_values(array_unique($lookup)))
            ->where('subject_type', $aggregateType)
            ->where('subject_id', $aggregateId)
            ->value('source_event_id');
        if (is_string($existing)) {
            return $existing;
        }

        return $value;
    }

    private function idempotencyKey(array $context): ?string
    {
        $value = $context['idempotency_key'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function arrayContext(array $context, string $key): array
    {
        return is_array($context[$key] ?? null) ? $context[$key] : [];
    }

    private function businessContext(array $context): array
    {
        unset($context['before'], $context['after'], $context['diff'], $context['source_event_id']);

        return $context;
    }
}
