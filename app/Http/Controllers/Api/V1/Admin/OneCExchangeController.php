<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OneCExchangeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\OneCExchange\CreateOneCTokenRequest;
use App\Http\Requests\Api\V1\Admin\OneCExchange\ManualOneCExchangeRequest;
use App\Http\Requests\Api\V1\Admin\OneCExchange\StoreOneCMappingRequest;
use App\Http\Responses\AdminResponse;
use App\Services\OneCExchange\OneCExchangeJournalService;
use App\Services\OneCExchange\OneCExchangeMonitoringService;
use App\Services\OneCExchange\OneCExchangeRunService;
use App\Services\OneCExchange\OneCManualExchangeService;
use App\Services\OneCExchange\OneCMappingService;
use App\Services\OneCExchange\OneCTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OneCExchangeController extends Controller
{
    public function __construct(
        private readonly OneCTokenService $tokens,
        private readonly OneCMappingService $mappings,
        private readonly OneCExchangeRunService $runs,
        private readonly OneCExchangeJournalService $journal,
        private readonly OneCExchangeMonitoringService $monitoring,
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

    public function monitoring(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $filters = $this->monitoringFilters($request);

            if ($filters instanceof JsonResponse) {
                return $filters;
            }

            return AdminResponse::success(
                $this->monitoring->monitoring($organizationId, $filters),
                trans_message('one_c_exchange.monitoring_loaded')
            );
        });
    }

    public function health(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $filters = $this->monitoringFilters($request);

            if ($filters instanceof JsonResponse) {
                return $filters;
            }

            return AdminResponse::success(
                $this->monitoring->health($organizationId, $filters),
                trans_message('one_c_exchange.health_loaded')
            );
        });
    }

    public function metrics(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $filters = $this->monitoringFilters($request);

            if ($filters instanceof JsonResponse) {
                return $filters;
            }

            return AdminResponse::success(
                $this->monitoring->metrics($organizationId, $filters),
                trans_message('one_c_exchange.metrics_loaded')
            );
        });
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

    public function referenceMappings(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $mappings = $this->mappings->registry(
                $organizationId,
                $request->only(['scope', 'status', 'source', 'search']),
                (int) $request->integer('per_page', 20)
            );

            return AdminResponse::paginated(
                $mappings->items(),
                $this->paginationMeta($mappings),
                trans_message('one_c_exchange.reference_mappings_loaded')
            );
        });
    }

    public function showReferenceMapping(int $mappingId): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($mappingId): JsonResponse {
            $mapping = $this->mappings->show($organizationId, $mappingId);

            if (!$mapping) {
                return AdminResponse::error(
                    trans_message('one_c_exchange.mapping_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success($mapping, trans_message('one_c_exchange.reference_mapping_loaded'));
        });
    }

    public function storeReferenceMapping(StoreOneCMappingRequest $request): JsonResponse
    {
        return $this->guarded(fn (int $organizationId) => AdminResponse::success(
            $this->mappings->payload($this->mappings->upsert($organizationId, $request->validated())),
            trans_message('one_c_exchange.reference_mapping_saved')
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
                $this->paginationMeta($history),
                trans_message('one_c_exchange.history_loaded')
            );
        });
    }

    public function journal(Request $request): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($request): JsonResponse {
            $journal = $this->journal->list(
                $organizationId,
                $request->only(['scope', 'status', 'direction', 'entity_type', 'search']),
                (int) $request->integer('per_page', 20)
            );

            return AdminResponse::paginated(
                $journal->items(),
                $this->paginationMeta($journal),
                trans_message('one_c_exchange.operations_loaded')
            );
        });
    }

    public function showJournalOperation(int $operationId): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($operationId): JsonResponse {
            $operation = $this->journal->show($organizationId, $operationId);

            if (!$operation) {
                return AdminResponse::error(
                    trans_message('one_c_exchange.operation_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success($operation, trans_message('one_c_exchange.operation_loaded'));
        });
    }

    public function retryJournalOperation(int $operationId): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($operationId): JsonResponse {
            $result = $this->journal->retry($organizationId, $operationId, Auth::id());

            if (!$result['operation']) {
                return AdminResponse::error(
                    trans_message('one_c_exchange.operation_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (!$result['allowed']) {
                return AdminResponse::error((string) $result['message'], Response::HTTP_CONFLICT);
            }

            return AdminResponse::success($result['operation'], (string) $result['message']);
        });
    }

    public function deadLetterJournalOperation(int $operationId): JsonResponse
    {
        return $this->guarded(function (int $organizationId) use ($operationId): JsonResponse {
            $operation = $this->journal->moveToDeadLetter($organizationId, $operationId);

            if (!$operation) {
                return AdminResponse::error(
                    trans_message('one_c_exchange.operation_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success($operation, trans_message('one_c_exchange.operation_dead_lettered'));
        });
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function scopeValues(): array
    {
        return array_map(static fn (OneCExchangeScope $scope): string => $scope->value, OneCExchangeScope::cases());
    }

    private function monitoringFilters(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'scope' => ['nullable', 'string', Rule::in($this->scopeValues())],
            'direction' => ['nullable', 'string', Rule::in(['import', 'export', 'prohelper_to_1c', '1c_to_prohelper'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'window_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        if ($validator->fails()) {
            return AdminResponse::error(
                trans_message('one_c_exchange.monitoring_filters_invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $validator->validated();
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
