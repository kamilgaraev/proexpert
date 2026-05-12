<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\OneCExchange\CreateOneCTokenRequest;
use App\Http\Requests\Api\V1\Admin\OneCExchange\ManualOneCExchangeRequest;
use App\Http\Requests\Api\V1\Admin\OneCExchange\StoreOneCMappingRequest;
use App\Http\Responses\AdminResponse;
use App\Services\OneCExchange\OneCExchangeRunService;
use App\Services\OneCExchange\OneCManualExchangeService;
use App\Services\OneCExchange\OneCMappingService;
use App\Services\OneCExchange\OneCTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OneCExchangeController extends Controller
{
    public function __construct(
        private readonly OneCTokenService $tokens,
        private readonly OneCMappingService $mappings,
        private readonly OneCExchangeRunService $runs,
        private readonly OneCManualExchangeService $manualExchange
    ) {
    }

    public function status(): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->runs->status($organizationId),
            trans_message('one_c_exchange.status_loaded')
        ));
    }

    public function tokens(): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->tokens->listTokens($organizationId),
            trans_message('one_c_exchange.tokens_loaded')
        ));
    }

    public function createToken(CreateOneCTokenRequest $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $result = $this->tokens->createToken($organizationId, (string) $request->validated('label'));

            return AdminResponse::success([
                'token' => $result['token'],
                'plain_token' => $result['plain_token'],
            ], trans_message('one_c_exchange.token_created'));
        });
    }

    public function revokeToken(int $tokenId): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($tokenId): JsonResponse {
            if (!$this->tokens->revokeToken($organizationId, $tokenId)) {
                return AdminResponse::error(
                    trans_message('one_c_exchange.token_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success(null, trans_message('one_c_exchange.token_revoked'));
        });
    }

    public function mappings(Request $request): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->mappings->list($organizationId, $request->query('scope')),
            trans_message('one_c_exchange.mappings_loaded')
        ));
    }

    public function storeMapping(StoreOneCMappingRequest $request): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->mappings->upsert($organizationId, $request->validated()),
            trans_message('one_c_exchange.mapping_saved')
        ));
    }

    public function import(ManualOneCExchangeRequest $request): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->manualExchange->import($organizationId, Auth::id(), $request->validated()),
            trans_message('one_c_exchange.import_completed')
        ));
    }

    public function export(ManualOneCExchangeRequest $request): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->manualExchange->export($organizationId, Auth::id(), $request->validated()),
            trans_message('one_c_exchange.export_completed')
        ));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $history = $this->runs->history($organizationId, (int) $request->integer('per_page', 20));

            return AdminResponse::paginated(
                $history->items(),
                [
                    'current_page' => $history->currentPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                    'last_page' => $history->lastPage(),
                ],
                trans_message('one_c_exchange.history_loaded')
            );
        });
    }

    private function guarded(callable $callback): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('one_c_exchange.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            return $callback($organizationId);
        } catch (Throwable $exception) {
            Log::error('One C exchange request failed', [
                'organization_id' => $organizationId,
                'user_id' => Auth::id(),
                'exception' => $exception,
            ]);

            return AdminResponse::error(
                trans_message('one_c_exchange.exchange_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function resolveOrganizationId(): ?int
    {
        $organizationId = request()->attributes->get('current_organization_id')
            ?? Auth::user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
