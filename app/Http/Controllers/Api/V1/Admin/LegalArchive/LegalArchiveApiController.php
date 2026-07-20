<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

abstract class LegalArchiveApiController extends Controller
{
    protected function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    protected function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    protected function failure(Throwable $error, Request $request, string $operation, array $context = []): JsonResponse
    {
        if ($error instanceof LegalArchiveLockConflict) {
            return AdminResponse::error(
                trans_message('legal_archive.messages.lock_conflict'),
                409,
                null,
                ['current_lock_version' => $error->currentLockVersion],
            );
        }
        if ($error instanceof AuthorizationException) {
            return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
        }
        if ($error instanceof ValidationException) {
            return AdminResponse::error(
                trans_message('legal_archive.messages.validation_error'),
                422,
                $error->errors(),
            );
        }
        if ($error instanceof DomainException) {
            $key = 'legal_archive.domain_errors.'.$error->getMessage();

            return AdminResponse::error(
                Lang::has($key) ? trans_message($key) : trans_message('legal_archive.messages.operation_conflict'),
                409,
            );
        }

        Log::error('legal_archive.api.'.$operation.'_failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $this->organizationId($request),
            'error_class' => $error::class,
            ...$context,
        ]);

        return AdminResponse::error(trans_message('legal_archive.messages.operation_failed'), 500);
    }

    protected function etag(JsonResponse $response, LegalArchiveDocument $document): JsonResponse
    {
        return $response->withHeaders(['ETag' => sprintf('"legal-document-%d-v%d"', $document->id, $document->lock_version)]);
    }
}
