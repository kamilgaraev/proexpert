<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** The intent commits before storage; publishing documents and its receipt share the second transaction. */
final readonly class DocumentAdmissionOperation
{
    public const TABLE = 'estimate_generation_document_admissions';

    public function __construct(private EstimateGenerationMutationPolicy $policy,
        private EstimateGenerationActionAuthorization $authorizer, private DocumentGenerationReadinessService $readiness) {}

    /** @param Closure(EstimateGenerationSession):PreparedDocumentAdmission $prepare
     * @param  Closure(EstimateGenerationSession,PreparedDocumentAdmission,UnpublishedDocumentObjectBatch):UploadDocumentsResult  $publish
     */
    public function run(EstimateGenerationSession $session, int $expectedVersion, User $user, ?string $requestId,
        string $kind, string $payloadHash, Closure $prepare, Closure $publish): UploadDocumentsResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('document_admission_requires_operation_transaction_boundary');
        }
        $requestId ??= (string) Str::uuid();
        if (! Str::isUuid($requestId) || ! in_array($kind, ['upload', 'reuse'], true)) {
            throw new DocumentAdmissionConflict('document_admission_request_invalid');
        }
        $hash = hash('sha256', implode('|', [$session->organization_id, $session->project_id, $session->id,
            $user->id, $expectedVersion, $kind, $payloadHash]));
        $prepared = null;
        $replay = null;
        $id = DB::transaction(function () use ($session, $expectedVersion, $user, $requestId, $kind, $hash, $prepare, &$prepared, &$replay): int {
            $current = $this->lockSession($session);
            $this->authorizer->authorize($user, $current, 'estimate_generation.upload_documents');
            $existing = DB::table(self::TABLE)->where('organization_id', $current->organization_id)
                ->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->session_id !== (int) $current->id || (int) $existing->user_id !== (int) $user->id
                    || ! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new DocumentAdmissionConflict('document_admission_request_changed');
                }
                if ($existing->status !== 'accepted') {
                    throw new DocumentAdmissionConflict('document_admission_'.$existing->status);
                }
                $replay = $this->result($current, json_decode($existing->document_ids, true, 512, JSON_THROW_ON_ERROR));

                return (int) $existing->id;
            }
            $this->policy->documents($current, $expectedVersion);
            if (DB::table(self::TABLE)->where('session_id', $current->id)->where('status', 'pending')->exists()) {
                throw new DocumentAdmissionConflict('document_admission_pending');
            }
            $prepared = $prepare($current);

            return DB::table(self::TABLE)->insertGetId(['organization_id' => $current->organization_id,
                'project_id' => $current->project_id, 'session_id' => $current->id, 'user_id' => $user->id,
                'request_id' => $requestId, 'kind' => $kind, 'expected_version' => $expectedVersion, 'request_hash' => $hash,
                'status' => 'pending', 'reserved_slots' => count($prepared->objects),
                'objects' => json_encode($prepared->snapshot(), JSON_THROW_ON_ERROR), 'document_ids' => '[]',
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        }, 1);
        if ($replay instanceof UploadDocumentsResult) {
            return $replay;
        }
        if (! $prepared instanceof PreparedDocumentAdmission) {
            throw new \LogicException('document_admission_preparation_missing');
        }
        $batch = PreparedDocumentAdmission::batch($session, $prepared->snapshot());
        try {
            return DB::transaction(function () use ($session, $expectedVersion, $user, $id, $prepared, $batch, $publish): UploadDocumentsResult {
                $current = $this->lockSession($session);
                $this->authorizer->authorize($user, $current, 'estimate_generation.upload_documents');
                $this->policy->documents($current, $expectedVersion);
                $intent = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                if ($intent === null || $intent->status !== 'pending' || strtotime($intent->expires_at) <= now()->timestamp) {
                    throw new DocumentAdmissionConflict('document_admission_expired');
                }
                foreach ($prepared->objects as $object) {
                    (new DocumentObjectPublicationLock)->acquire($object->path);
                }
                $result = $publish($current, $prepared, $batch);
                $this->authorizer->authorize($user, $current, 'estimate_generation.upload_documents');
                DB::table(self::TABLE)->where('id', $id)->update(['status' => 'accepted',
                    'document_ids' => json_encode($result->documents->pluck('id')->all(), JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                return $result;
            }, 1);
        } catch (Throwable $error) {
            $this->fail($session, $id);
            foreach ($batch->objects() as $object) {
                try {
                    app(DeleteUnpublishedDocumentObject::class)->handle($object);
                } catch (Throwable $cleanupError) {
                    Log::warning('estimate_generation.document_admission_cleanup_deferred', ['admission_id' => $id,
                        'failure_code' => 'document_cleanup_transport_failed', 'exception_class' => $cleanupError::class]);
                }
            }
            throw $error;
        }
    }

    public function recover(int $limit = 10): int
    {
        $ids = DB::table(self::TABLE)->where('status', 'pending')->where('expires_at', '<=', now())
            ->orderBy('id')->limit(min(100, max(1, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $row = DB::table(self::TABLE)->where('id', $id)->first();
                if ($row === null) {
                    return 0;
                }
                $session = EstimateGenerationSession::query()->whereKey($row->session_id)
                    ->where('organization_id', $row->organization_id)->where('project_id', $row->project_id)
                    ->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($session === null) {
                    return 0;
                }
                $row = DB::table(self::TABLE)->where('id', $id)->where('status', 'pending')
                    ->where('expires_at', '<=', now())->lockForUpdate()->first();
                if ($row === null) {
                    return 0;
                }
                DB::table(self::TABLE)->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
                app(UnpublishedDocumentCleanupJournal::class)->recordFailedAdmission((int) $id);

                return 1;
            }, 1);
        }

        return $count;
    }

    private function fail(EstimateGenerationSession $session, int $id): void
    {
        DB::transaction(function () use ($session, $id): void {
            $this->lockSession($session);
            $row = DB::table(self::TABLE)->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            DB::table(self::TABLE)->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
            app(UnpublishedDocumentCleanupJournal::class)->recordFailedAdmission($id);
        }, 1);
    }

    private function lockSession(EstimateGenerationSession $session): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->whereKey($session->id)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->lockForUpdate()->firstOrFail();
    }

    /** @param list<int> $ids */
    private function result(EstimateGenerationSession $session, array $ids): UploadDocumentsResult
    {
        $documents = EstimateGenerationDocument::query()->where('session_id', $session->id)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->whereIn('id', $ids)->orderBy('id')->get();
        if ($documents->count() !== count($ids)) {
            throw new DocumentAdmissionConflict('document_admission_receipt_unavailable');
        }

        return new UploadDocumentsResult($documents, $this->readiness->evaluate($session->load('documents'))['summary']);
    }
}
