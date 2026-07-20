<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\LegalArchive\Editor\EditorCallbackInput;
use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegalDocumentEditorController extends Controller
{
    public function __construct(private readonly LegalDocumentEditorSessionService $sessions) {}

    public function callback(Request $request, string $session): JsonResponse
    {
        try {
            $data = $request->validate([
                'key' => ['required', 'string', 'max:191'],
                'status' => ['required', 'integer', 'between:1,7'],
                'url' => ['nullable', 'url:https', 'max:4096'],
                'token' => ['nullable', 'string', 'max:16384'],
            ]);
            $authorization = (string) $request->bearerToken();
            $token = $authorization !== '' ? $authorization : (string) ($data['token'] ?? '');
            $replay = hash('sha256', json_encode([
                'session' => $session,
                'key' => $data['key'],
                'status' => (int) $data['status'],
                'url' => $data['url'] ?? null,
                'forcesavetype' => $request->input('forcesavetype'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->sessions->handleCallback(new EditorCallbackInput(
                $session,
                (string) $data['key'],
                (int) $data['status'],
                isset($data['url']) ? (string) $data['url'] : null,
                $replay,
                $token,
            ));

            return new JsonResponse(['error' => 0]);
        } catch (Throwable $error) {
            Log::warning('legal_archive.editor.callback_rejected', [
                'session_id_hash' => hash('sha256', $session),
                'error_class' => $error::class,
            ]);

            return new JsonResponse(['error' => 1]);
        }
    }
}
