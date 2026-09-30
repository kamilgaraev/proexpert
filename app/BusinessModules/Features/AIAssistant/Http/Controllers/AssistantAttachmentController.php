<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantAttachmentRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssistantAttachmentController extends AbstractAssistantApiController
{
    public function __construct(private readonly AssistantChatAttachmentService $attachments) {}

    public function upload(AssistantAttachmentRequest $request): JsonResponse
    {
        return $this->success($request, $this->attachments->upload($request->file('image'), $this->actor($request), $this->organizationId($request), $request->filled('conversation_id') ? $request->integer('conversation_id') : null)->publicMetadata(), 201);
    }

    public function content(Request $request, string $attachment): Response
    {
        [$bytes, $mime] = $this->attachments->content($attachment, $this->actor($request), $this->organizationId($request));

        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Length' => (string) strlen($bytes), 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }
}
