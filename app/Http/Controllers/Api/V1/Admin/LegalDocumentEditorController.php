<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\LegalArchive\Editor\EditorCallbackInput;
use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegalDocumentEditorController extends Controller
{
    public function __construct(private readonly LegalDocumentEditorSessionService $sessions) {}

    public function open(Request $request, string $version): JsonResponse
    {
        try {
            $actor = $request->user();
            $found = $this->version($request, $version);
            if (! $actor instanceof User || ! $found instanceof LegalArchiveDocumentVersion) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }

            $mode = (string) $request->input('mode', 'edit');
            if (! in_array($mode, ['view', 'review', 'edit'], true)) {
                throw new DomainException('legal_document_editor_mode_invalid');
            }
            $upgradeMode = filter_var($request->input('upgrade_mode', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (! is_bool($upgradeMode)) {
                throw new DomainException('legal_document_editor_mode_invalid');
            }

            return AdminResponse::success($this->sessions->open($found, $actor, $mode, $upgradeMode), trans_message('legal_archive.messages.editor_opened'));
        } catch (Throwable $error) {
            if ($error instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            if ($error instanceof DomainException) {
                return AdminResponse::error(trans_message('legal_archive.messages.editor_unavailable'), 409);
            }
            Log::error('legal_archive.editor.open_failed', [
                'user_id' => $request->user()?->id, 'version_id' => $version,
                'error_class' => $error::class,
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.editor_open_error'), 500);
        }
    }

    public function callback(Request $request, string $session): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 65536) {
                throw new DomainException('legal_document_editor_callback_too_large');
            }
            $data = $request->validate([
                'key' => ['required', 'string', 'max:191'], 'status' => ['required', 'integer', 'between:1,7'],
                'url' => ['nullable', 'url:https', 'max:4096'], 'token' => ['nullable', 'string', 'max:16384'],
            ]);
            $authorization = (string) $request->bearerToken();
            $token = $authorization !== '' ? $authorization : (string) ($data['token'] ?? '');
            $replay = hash('sha256', json_encode([
                'session' => $session, 'key' => $data['key'], 'status' => (int) $data['status'],
                'url' => $data['url'] ?? null, 'forcesavetype' => $request->input('forcesavetype'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->sessions->handleCallback(new EditorCallbackInput(
                $session, (string) $data['key'], (int) $data['status'],
                isset($data['url']) ? (string) $data['url'] : null, $replay, $token,
            ));

            return new JsonResponse(['error' => 0]);
        } catch (Throwable $error) {
            Log::warning('legal_archive.editor.callback_rejected', [
                'session_id_hash' => hash('sha256', $session), 'error_class' => $error::class,
            ]);

            return new JsonResponse(['error' => 1]);
        }
    }

    private function version(Request $request, string $version): ?LegalArchiveDocumentVersion
    {
        $organizationId = (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id ?? 0);

        return LegalArchiveDocumentVersion::query()->with(['documentFile.document', 'organization'])
            ->whereKey((int) $version)
            ->where('organization_id', $organizationId)->first();
    }
}
