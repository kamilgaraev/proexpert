<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentVersionResource;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegalDocumentVersionAccessController extends Controller
{
    public function __construct(private readonly LegalDocumentDownloadService $downloads) {}

    public function preview(Request $request, string $version): JsonResponse
    {
        return $this->fileUrl($request, $version, 'preview');
    }

    public function download(Request $request, string $version): JsonResponse
    {
        return $this->fileUrl($request, $version, 'download');
    }

    private function fileUrl(Request $request, string $version, string $purpose): JsonResponse
    {
        try {
            $actor = $request->user();
            $found = $this->version($request, $version);
            if (! $actor instanceof User || ! $found instanceof LegalArchiveDocumentVersion) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $url = $this->downloads->temporaryUrl($found, $actor, $purpose);

            return AdminResponse::success([
                'url' => $url,
                'expires_in_seconds' => max(60, (int) config('file-uploads.legal_archive.temporary_url_minutes', 5) * 60),
                'version' => new LegalArchiveDocumentVersionResource($found),
            ], trans_message('legal_archive.messages.file_url_created'))->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (Throwable $error) {
            if ($error instanceof AuthorizationException || $error instanceof DomainException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            Log::error('legal_archive.version.file_url_failed', [
                'user_id' => $request->user()?->id,
                'version_id' => $version,
                'purpose' => $purpose,
                'error_class' => $error::class,
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.file_url_error'), 500);
        }
    }

    private function version(Request $request, string $version): ?LegalArchiveDocumentVersion
    {
        $organizationId = (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? 0);

        return LegalArchiveDocumentVersion::query()->with(['documentFile.document', 'organization'])
            ->whereKey((int) $version)
            ->where('organization_id', $organizationId)
            ->first();
    }
}
