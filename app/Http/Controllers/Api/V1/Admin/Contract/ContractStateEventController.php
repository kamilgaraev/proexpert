<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Services\Contract\ContractStateEventService;
use App\Services\Contract\ContractStateCalculatorService;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Exception;

class ContractStateEventController extends Controller
{
    protected ContractService $contractService;
    protected ContractStateEventService $stateEventService;
    protected ContractStateCalculatorService $stateCalculatorService;

    public function __construct(
        ContractService $contractService,
        ContractStateEventService $stateEventService,
        ContractStateCalculatorService $stateCalculatorService
    ) {
        $this->contractService = $contractService;
        $this->stateEventService = $stateEventService;
        $this->stateCalculatorService = $stateCalculatorService;
    }

    /**
     * Получить историю событий для договора
     */
    public function index(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден или нет доступа'
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$contractModel->usesEventSourcing()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Договор не использует Event Sourcing (legacy)'
                ]);
            }

            $timeline = $this->stateEventService->getTimeline($contractModel);
            
            return response()->json([
                'success' => true,
                'data' => $timeline->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'event_type' => $event->event_type->value,
                        'triggered_by_type' => $event->triggered_by_type,
                        'triggered_by_id' => $event->triggered_by_id,
                        'specification_id' => $event->specification_id,
                        'amount_delta' => $event->amount_delta,
                        'effective_from' => $event->effective_from?->format('Y-m-d'),
                        'supersedes_event_id' => $event->supersedes_event_id,
                        'metadata' => $event->metadata,
                        'created_at' => $event->created_at?->toIso8601String(),
                        'created_by' => $event->createdBy ? [
                            'id' => $event->createdBy->id,
                            'name' => $event->createdBy->name ?? 'System'
                        ] : null,
                        'specification' => $event->specification ? [
                            'id' => $event->specification->id,
                            'number' => $event->specification->number,
                            'total_amount' => $event->specification->total_amount,
                        ] : null,
                        'is_active' => $event->isActive(),
                    ];
                })
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении истории событий',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить timeline событий с деталями
     */
    public function timeline(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $asOfDate = $request->query('as_of_date') ? Carbon::parse($request->query('as_of_date')) : null;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден или нет доступа'
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$contractModel->usesEventSourcing()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Договор не использует Event Sourcing (legacy)'
                ]);
            }

            $timeline = $this->stateEventService->getTimeline($contractModel, $asOfDate);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'contract_id' => $contractModel->id,
                    'as_of_date' => $asOfDate?->format('Y-m-d'),
                    'events' => $timeline->map(function ($event) {
                        return [
                            'id' => $event->id,
                            'event_type' => $event->event_type->value,
                            'description' => $this->getEventDescription($event),
                            'amount_delta' => $event->amount_delta,
                            'effective_from' => $event->effective_from?->format('Y-m-d'),
                            'created_at' => $event->created_at?->toIso8601String(),
                            'created_by' => $event->createdBy?->name ?? 'System',
                            'specification' => $event->specification ? [
                                'id' => $event->specification->id,
                                'number' => $event->specification->number,
                            ] : null,
                            'is_active' => $event->isActive(),
                        ];
                    })
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении timeline',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить текущее состояние договора
     */
    public function currentState(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден или нет доступа'
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$contractModel->usesEventSourcing()) {
                // Для legacy договоров возвращаем простое состояние
                return response()->json([
                    'success' => true,
                    'data' => [
                        'contract_id' => $contractModel->id,
                        'uses_event_sourcing' => false,
                        'total_amount' => $contractModel->total_amount,
                        'active_specification' => $contractModel->specifications()->first(),
                        'message' => __('contract.legacy_event_sourcing_unavailable', [], 'ru'),
                        'activation_hint' => __('contract.event_sourcing_activation_hint', [], 'ru')
                    ]
                ]);
            }

            $state = $this->stateEventService->getCurrentState($contractModel);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'contract_id' => $state['contract_id'],
                    'total_amount' => $state['total_amount'],
                    'active_specification' => $state['active_specification'] ? [
                        'id' => $state['active_specification']->id,
                        'number' => $state['active_specification']->number,
                        'total_amount' => $state['active_specification']->total_amount,
                    ] : null,
                    'active_events_count' => $state['active_events']->count(),
                    'as_of_date' => $state['as_of_date']->format('Y-m-d'),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении состояния',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить состояние договора на определенную дату
     */
    public function stateAtDate(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $asOfDate = $request->query('as_of_date') ? Carbon::parse($request->query('as_of_date')) : null;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], Response::HTTP_BAD_REQUEST);
        }

        if (!$asOfDate) {
            return response()->json([
                'success' => false,
                'message' => 'Параметр as_of_date обязателен'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден или нет доступа'
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$contractModel->usesEventSourcing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Договор не использует Event Sourcing (legacy)'
                ], Response::HTTP_BAD_REQUEST);
            }

            $state = $this->stateEventService->getStateAtDate($contractModel, $asOfDate);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'contract_id' => $state['contract_id'],
                    'total_amount' => $state['total_amount'],
                    'active_specification' => $state['active_specification'] ? [
                        'id' => $state['active_specification']->id,
                        'number' => $state['active_specification']->number,
                        'total_amount' => $state['active_specification']->total_amount,
                    ] : null,
                    'active_events_count' => $state['active_events']->count(),
                    'as_of_date' => $state['as_of_date']->format('Y-m-d'),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении состояния на дату',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить описательное название события
     */
    private function getEventDescription($event): string
    {
        $type = $event->event_type->value;
        $delta = number_format($event->amount_delta, 2, '.', ' ');
        
        switch ($type) {
            case 'created':
                return "Создание договора на сумму {$delta} руб.";
            case 'amended':
                return "Изменение договора: +{$delta} руб.";
            case 'superseded':
                return "Аннулирование предыдущего изменения: {$delta} руб.";
            case 'cancelled':
                return "Отмена: {$delta} руб.";
            default:
                return "Событие типа {$type}";
        }
    }
}

