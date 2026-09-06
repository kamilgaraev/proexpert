<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeAssistantRequest;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAssistantService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KnowledgeAssistantController extends Controller
{
    public function __construct(
        private readonly KnowledgeAssistantService $assistant,
        private readonly KnowledgeAccessContextFactory $contexts,
    ) {
    }

    public function admin(KnowledgeAssistantRequest $request): JsonResponse
    {
        return $this->respond($request, KnowledgeSurface::ADMIN);
    }

    public function landing(KnowledgeAssistantRequest $request): JsonResponse
    {
        return $this->respond($request, KnowledgeSurface::LK);
    }

    public function mobile(KnowledgeAssistantRequest $request): JsonResponse
    {
        return $this->respond($request, KnowledgeSurface::MOBILE);
    }

    private function respond(KnowledgeAssistantRequest $request, KnowledgeSurface $surface): JsonResponse
    {
        $response = match ($surface) {
            KnowledgeSurface::ADMIN => AdminResponse::class,
            KnowledgeSurface::LK => LandingResponse::class,
            default => MobileResponse::class,
        };

        try {
            $input = Request::create('/', 'POST', $request->validated());
            $input->setUserResolver(fn () => $request->user());
            $context = $this->contexts->fromRequest($input, $surface);

            return $response::success(
                $this->assistant->answer((string) $request->validated('question'), $context),
                trans_message('knowledge_assistant.answered'),
            );
        } catch (Throwable $exception) {
            $limited = $exception->getMessage() === 'knowledge_assistant_limit';
            Log::warning('knowledge_assistant.request_failed', [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'surface' => $surface->value,
                'exception_class' => $exception::class,
            ]);

            return $response::error(
                trans_message($limited ? 'knowledge_assistant.limit' : 'knowledge_assistant.unavailable'),
                $limited ? 429 : 503,
            );
        }
    }
}
