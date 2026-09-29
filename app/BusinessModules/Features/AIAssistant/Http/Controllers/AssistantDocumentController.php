<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\Documents\RegisterAssistantDocumentRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\Documents\ConfirmAssistantDocumentOcrRequest;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Jobs\ProcessAssistantDocument;
use App\Jobs\ProcessAssistantDocumentOcr;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantDocumentController extends Controller
{
    public function __construct(private readonly AssistantDocumentService $documents, private readonly \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService $budgets) {}

    public function settings(Request $request): JsonResponse
    {
        $response = $this->responseClass($request);
        $actor = $request->user();
        if (! $actor instanceof User) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try { return $response::success($this->settingsPayload($this->budgets->settings($actor, $this->organizationId($request)))); }
        catch (Throwable) { return $response::error(trans_message('ai_assistant.document_budget_forbidden'), 403); }
    }

    public function approveBudget(\App\BusinessModules\Features\AIAssistant\Http\Requests\Documents\ApproveAssistantDocumentBudgetRequest $request): JsonResponse
    {
        $response = $this->responseClass($request);
        $actor = $request->user();
        if (! $actor instanceof User) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try {
            $data = $request->validated();
            return $response::success($this->settingsPayload($this->budgets->approve($actor, $this->organizationId($request), (bool) $data['enabled'], (int) $data['limit_minor'], $data['scope'])));
        } catch (Throwable) { return $response::error(trans_message('ai_assistant.document_budget_failed'), 422); }
    }

    private function settingsPayload(\App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings $settings): array
    {
        return ['enabled' => $settings->background_ocr_enabled, 'scope' => $settings->scope, 'limit_minor' => $settings->limit_minor,
            'reserved_minor' => $settings->reserved_minor, 'spent_minor' => $settings->spent_minor,
            'available_minor' => max(0, $settings->limit_minor - $settings->reserved_minor - $settings->spent_minor),
            'scanned_count' => $settings->scanned_count, 'last_file_id' => $settings->last_file_id,
            'scan_completed_at' => $settings->scan_completed_at?->toAtomString()];
    }
    public function register(RegisterAssistantDocumentRequest $request): JsonResponse
    {
        $response = $this->responseClass($request);
        $user = $request->user(); $organizationId = $this->organizationId($request);
        if (! $user instanceof User || $organizationId < 1) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try { $data = $request->validated(); $document = $this->documents->register($user, $organizationId, $data['parent_entity_type'], $data['parent_entity_id'], $data['storage_path'], $data['filename'], $data['mime_type'], isset($data['project_id']) ? (int) $data['project_id'] : null); ProcessAssistantDocument::dispatch($document->id)->afterCommit(); return $response::success($this->payload($document), trans_message('ai_assistant.document_queued')); }
        catch (Throwable $e) { Log::warning('ai_assistant.document.register_failed', ['organization_id' => $organizationId, 'user_id' => $user->id ?? null, 'exception_class' => $e::class]); return $response::error(trans_message('ai_assistant.document_register_failed'), 422); }
    }
    public function status(Request $request, AIAssistantDocument $document): JsonResponse
    {
        $response = $this->responseClass($request);
        $user = $request->user(); if (!$user instanceof User) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try { return $response::success($this->payload($this->documents->status($user, $this->organizationId($request), $document))); } catch (Throwable) { return $response::error(trans_message('ai_assistant.document_not_found'), 404); }
    }
    public function quoteOcr(Request $request, AIAssistantDocument $document): JsonResponse
    {
        $response = $this->responseClass($request);
        $user = $request->user(); $organizationId = $this->organizationId($request); if (!$user instanceof User) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try { return $response::success($this->documents->quoteOcr($user, $organizationId, $document)); } catch (Throwable $e) { Log::warning('ai_assistant.document.ocr_quote_failed', ['document_id' => $document->id, 'exception_class' => $e::class]); return $response::error(trans_message('ai_assistant.document_ocr_quote_failed'), 422); }
    }
    public function confirmOcr(ConfirmAssistantDocumentOcrRequest $request, AIAssistantDocument $document): JsonResponse
    {
        $response = $this->responseClass($request);
        $user = $request->user(); $organizationId = $this->organizationId($request); if (!$user instanceof User) return $response::error(trans_message('ai_assistant.unauthorized'), 401);
        try { $data = $request->validated(); $document = $this->documents->confirmOcr($user, $organizationId, $document, $data['quote_id'], $data['request_id']); ProcessAssistantDocumentOcr::dispatch($document->id)->afterCommit(); return $response::success($this->payload($document), trans_message('ai_assistant.document_ocr_queued')); } catch (Throwable $e) { Log::warning('ai_assistant.document.ocr_confirm_failed', ['document_id' => $document->id, 'exception_class' => $e::class]); return $response::error(trans_message('ai_assistant.document_ocr_confirm_failed'), 422); }
    }
    private function organizationId(Request $request): int { return (int) $request->attributes->get('current_organization_id', $request->user()?->current_organization_id ?? 0); }
    private function responseClass(Request $request): string
    {
        return $request->is('api/v1/admin/*') ? AdminResponse::class
            : ($request->is('api/v1/mobile/*') ? \App\Http\Responses\MobileResponse::class : \App\Http\Responses\LandingResponse::class);
    }
    private function payload(AIAssistantDocument $document): array { return ['id' => $document->id, 'status' => $document->status, 'coverage_status' => $document->coverage_status, 'filename' => $document->filename, 'processed_at' => $document->processed_at?->toAtomString(), 'page_count' => (int) ($document->metadata['page_count'] ?? 1), 'ocr_completed_pages' => (int) ($document->metadata['ocr_completed_pages'] ?? 0)]; }
}
