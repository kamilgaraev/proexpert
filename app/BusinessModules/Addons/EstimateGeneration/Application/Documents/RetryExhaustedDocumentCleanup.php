<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Operator-only recovery; never changes the immutable object identity or deletes storage directly. */
final readonly class RetryExhaustedDocumentCleanup
{
    public function __construct(private ImmutableAuditRecorder $audit, private PersistedDocumentObjectReferences $references) {}

    public function handle(int $organizationId, int $projectId, int $sessionId, int $debtId,
        string $identityHash, string $requestId, int $actorId, string $reasonCode): string
    {
        if (min($organizationId, $projectId, $sessionId, $debtId, $actorId) < 1 || ! Str::isUuid($requestId)
            || preg_match('/\A[a-f0-9]{64}\z/', $identityHash) !== 1
            || ! in_array($reasonCode, ['storage_access_restored', 'storage_transport_restored'], true)) {
            throw new InvalidArgumentException('document_cleanup_retry_input_invalid');
        }

        return DB::transaction(function () use ($organizationId, $projectId, $sessionId, $debtId, $identityHash, $requestId, $actorId, $reasonCode): string {
            $actor = SystemAdmin::query()->whereKey($actorId)->lockForUpdate()->first();
            if (! $actor instanceof SystemAdmin || ! $actor->isActive()
                || ! $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_OPERATE)) {
                throw new InvalidArgumentException('document_cleanup_retry_forbidden');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate-cleanup-retry:'.$organizationId.':'.$requestId]);
            $requestHash = hash('sha256', implode('|', [$organizationId, $projectId, $sessionId, $debtId, $identityHash, $actorId, $reasonCode]));
            $receipt = ImmutableAuditEvent::query()->where('organization_id', $organizationId)->where('domain', 'budgeting')
                ->where('source', 'document_cleanup_retry')->where('source_event_id', $requestId)->first();
            if ($receipt !== null) {
                if (($receipt->domain_context['request_hash'] ?? null) !== $requestHash) {
                    throw new InvalidArgumentException('document_cleanup_retry_request_conflict');
                }

                return (string) $receipt->id;
            }
            $debt = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debtId)
                ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
                ->lockForUpdate()->first();
            if ($debt === null || $debt->status !== 'exhausted' || (int) $debt->attempts !== 10
                || ! hash_equals((string) $debt->identity_hash, $identityHash)) {
                throw new InvalidArgumentException('document_cleanup_retry_state_changed');
            }
            $object = new UnpublishedDocumentObject($organizationId, $projectId, $sessionId, $debt->storage_path,
                $debt->checksum_sha256, (int) $debt->file_size_bytes);
            if (! hash_equals($object->fingerprint(), $identityHash) || $this->references->contains($object->path)) {
                throw new InvalidArgumentException('document_cleanup_retry_object_blocked');
            }
            $epoch = 1 + ImmutableAuditEvent::query()->where('organization_id', $organizationId)->where('domain', 'budgeting')
                ->where('source', 'document_cleanup_retry')->where('subject_type', 'estimate_generation_document_cleanup_debt')
                ->where('subject_id', (string) $debtId)->count();
            if ($epoch > 3) {
                throw new InvalidArgumentException('document_cleanup_retry_limit_reached');
            }
            $event = $this->audit->record(new ImmutableAuditEventData(organizationId: $organizationId, projectId: $projectId,
                domain: 'budgeting', eventType: 'document_cleanup.retry_requested', action: 'retry', source: 'document_cleanup_retry',
                actorType: 'system_admin', actorSnapshot: ['system_admin_id' => $actorId], sourceEventId: $requestId,
                idempotencyKey: $requestId, subjectType: 'estimate_generation_document_cleanup_debt', subjectId: $debtId,
                reason: $reasonCode, beforeState: ['status' => 'exhausted', 'attempts' => 10],
                afterState: ['status' => 'pending', 'attempts' => 0, 'retry_epoch' => $epoch],
                domainContext: ['request_hash' => $requestHash, 'identity_hash' => $identityHash, 'session_id' => $sessionId]));
            DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debtId)->update(['status' => 'pending',
                'attempts' => 0, 'last_failure_code' => null, 'next_attempt_at' => now(), 'updated_at' => now()]);

            return (string) $event->id;
        }, 1);
    }
}
