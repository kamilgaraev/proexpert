<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Mobile\LegalArchiveDocumentResource;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileLegalArchiveService;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class LegalArchiveController extends Controller
{
    public function __construct(private readonly MobileLegalArchiveService $archive) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            if ($actor === null || $request->integer('project_id') < 1) {
                return MobileResponse::error(trans_message('project.validation_failed'), 422);
            }
            $documents = $this->archive->documents($actor, (int) $actor->current_organization_id, $request->integer('project_id'));
            $summaries = $this->archive->summaries($actor, $documents->getCollection());
            $data = $documents->getCollection()->map(fn ($document): array => (new LegalArchiveDocumentResource($document, $summaries[(int) $document->id] ?? []))->resolve())->all();

            return MobileResponse::success(['data' => $data]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'index');
        }
    }

    public function show(Request $request, int $document): JsonResponse
    {
        try {
            $actor = $request->user();
            if ($actor === null) {
                return MobileResponse::error(trans_message('errors.unauthorized'), 401);
            }
            $found = $this->archive->document($actor, (int) $actor->current_organization_id, $document);

            return MobileResponse::success(new LegalArchiveDocumentResource($found, $this->archive->summary($actor, $found)));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'show', $document);
        }
    }

    public function action(Request $request, int $document, string $action): JsonResponse
    {
        try {
            if (! in_array($action, ['approve', 'reject', 'return'], true)) {
                return MobileResponse::error(trans_message('legal_archive.messages.validation_error'), 422);
            }
            $actor = $request->user();
            if ($actor === null) {
                return MobileResponse::error(trans_message('errors.unauthorized'), 401);
            }
            $validated = $request->validate([
                'idempotency_key' => ['required', 'uuid'],
                'target_step_id' => ['required', 'integer', 'min:1'],
                'instance_lock_version' => ['required', 'integer', 'min:0'],
                'step_lock_version' => ['required', 'integer', 'min:0'],
                'comment' => ['nullable', 'string', 'max:2000'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ]);
            $found = $this->archive->decide($actor, (int) $actor->current_organization_id, $document, $action, (int) $validated['target_step_id'], (string) $validated['idempotency_key'], (int) $validated['instance_lock_version'], (int) $validated['step_lock_version'], $validated['comment'] ?? null, $validated['reason'] ?? null);

            return MobileResponse::success(new LegalArchiveDocumentResource($found, $this->archive->summary($actor, $found)));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'action', $document);
        }
    }

    private function failure(Throwable $error, Request $request, string $operation, ?int $documentId = null): JsonResponse
    {
        if ($error instanceof AuthorizationException) {
            return MobileResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
        }
        if ($error instanceof ValidationException) {
            return MobileResponse::error(trans_message('legal_archive.messages.validation_error'), 422, $error->errors());
        }
        if ($error instanceof LegalArchiveLockConflict) {
            return MobileResponse::error(trans_message('legal_archive.messages.lock_conflict'), 409, null, [
                'current_lock_version' => $error->currentLockVersion,
                'refresh_url' => $error->refreshUrl,
            ]);
        }
        if ($error instanceof DomainException) {
            if ($error->getMessage() === 'legal_archive_document_not_found') {
                return MobileResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $key = 'legal_archive.domain_errors.'.$error->getMessage();

            return MobileResponse::error(
                Lang::has($key) ? trans_message($key) : trans_message('legal_archive.messages.operation_conflict'),
                409,
            );
        }
        Log::error('mobile.legal_archive.'.$operation.'.error', ['user_id' => $request->user()?->id, 'document_id' => $documentId, 'error' => $error->getMessage()]);

        return MobileResponse::error(trans_message('legal_archive.messages.operation_failed'), 500);
    }
}
