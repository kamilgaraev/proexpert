<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantCreditPurchaseRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantCreditQuoteRequest;
use App\Services\Credits\AICreditService;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantCreditsController extends AbstractAssistantApiController
{
    public function __construct(private readonly AICreditService $credits) {}

    public function balance(Request $request): JsonResponse
    {
        return $this->success($request, $this->credits->balance($this->organization($request), $this->actor($request)));
    }

    public function history(Request $request): JsonResponse
    {
        $page = $this->credits->history($this->organization($request), min(100, max(1, (int) $request->query('per_page', 20))), $this->actor($request));
        $response = $this->success($request, $page->items());
        $response->setData($response->getData(true) + ['meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
        return $response;
    }

    public function quote(AssistantCreditQuoteRequest $request): JsonResponse
    {
        try { return $this->success($request, $this->credits->quote($this->organization($request), $this->actor($request), $request->validated())); }
        catch (\App\Services\Credits\AICreditsNotReadyException) { return $this->creditError($request, 'ai_assistant.credits_not_ready', 503); }
        catch (\DomainException) { return $this->creditError($request, 'ai_assistant.request_failed', 422); }
    }

    public function purchase(AssistantCreditPurchaseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organization = $this->organization($request);
        if (! $this->credits->canPurchase($organization, $this->actor($request))) { return $this->creditError($request, 'ai_assistant.access_denied', 403); }
        try { return $this->success($request, $this->credits->purchase($organization, $this->actor($request), $data['pack_id'], $data['request_id'] ?? null)); }
        catch (\App\Services\Credits\AICreditsNotReadyException) { return $this->creditError($request, 'ai_assistant.credits_not_ready', 503); }
        catch (\App\Exceptions\Billing\CommercialCheckoutConflictException) { return $this->creditError($request, 'billing.checkout.conflict', 409); }
        catch (\DomainException|\InvalidArgumentException) { return $this->creditError($request, 'billing.checkout.invalid', 422); }
    }

    private function creditError(Request $request, string $messageKey, int $status): JsonResponse
    {
        $path = $request->path();
        $responseClass = str_contains($path, 'admin/ai-assistant') ? \App\Http\Responses\AdminResponse::class : (str_contains($path, 'mobile/ai-assistant') ? \App\Http\Responses\MobileResponse::class : \App\Http\Responses\LandingResponse::class);
        return $responseClass::error(trans_message($messageKey), $status);
    }

    private function organization(Request $request): Organization
    {
        $organization = Organization::query()->findOrFail($this->organizationId($request));
        abort_unless($this->actor($request)->belongsToOrganization((int) $organization->getKey()), 403);
        return $organization;
    }
}
