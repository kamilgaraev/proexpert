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
                $agreementNumber = $event->metadata['agreement_number'] ?? null;
                $reason = $event->metadata['reason'] ?? null;
                if ($agreementNumber) {
                    return "Создание дополнительного соглашения №{$agreementNumber} на сумму {$delta} руб.";
                }
                if ($reason === 'Изменение суммы контракта') {
                    $oldAmount = $event->metadata['old_amount'] ?? null;
                    $newAmount = $event->metadata['new_amount'] ?? null;
                    if ($oldAmount !== null && $newAmount !== null) {
                        $formattedOldAmount = number_format($oldAmount, 2, '.', ' ');
                        $formattedNewAmount = number_format($newAmount, 2, '.', ' ');
                        $formattedDelta = ($event->amount_delta >= 0 ? '+' : '') . number_format($event->amount_delta, 2, '.', ' ');
                        return "Изменение договора: {$formattedOldAmount} → {$formattedNewAmount} руб. ({$formattedDelta} руб.)";
                    }
                    $formattedDelta = ($event->amount_delta >= 0 ? '+' : '') . number_format($event->amount_delta, 2, '.', ' ');
                    return "Изменение договора: {$formattedDelta} руб.";
                }
                return "Изменение договора: +{$delta} руб.";
            case 'superseded':
                // Получаем информацию об аннулированном событии
                $supersedesEvent = $event->supersedesEvent;
                $reason = $event->metadata['reason'] ?? null;
                $supersededAgreementId = $event->metadata['superseded_agreement_id'] ?? null;
                
                // Получаем номер ДС, которое аннулирует (из triggered_by)
                $supersedingAgreement = null;
                if ($event->triggered_by_type === \App\Models\SupplementaryAgreement::class && $event->triggered_by_id) {
                    $supersedingAgreement = \App\Models\SupplementaryAgreement::find($event->triggered_by_id);
                }
                
                $description = "Аннулирование ";
                
                if ($supersedesEvent) {
                    // Определяем тип аннулированного события
                    $supersededType = $supersedesEvent->event_type->value;
                    $supersededDelta = number_format(abs($supersedesEvent->amount_delta), 2, '.', ' ');
                    
                    if ($supersededType === 'supplementary_agreement_created' || $supersededType === 'amended') {
                        // Пытаемся получить номер из metadata или из связанного ДС
                        $supersededAgreementNum = $supersedesEvent->metadata['agreement_number'] ?? null;
                        if (!$supersededAgreementNum && $supersedesEvent->triggered_by_id) {
                            $supersededAgreementModel = \App\Models\SupplementaryAgreement::find($supersedesEvent->triggered_by_id);
                            $supersededAgreementNum = $supersededAgreementModel?->number;
                        }
                        
                        if ($supersededAgreementNum) {
                            $description .= "дополнительного соглашения №{$supersededAgreementNum} ";
                        } else {
                            $description .= "дополнительного соглашения ";
                        }
                        
                        $description .= "на сумму {$supersededDelta} руб.";
                    } elseif ($supersededType === 'created') {
                        $description .= "создания договора на сумму {$supersededDelta} руб.";
                    } else {
                        $description .= "события типа '{$supersededType}' на сумму {$supersededDelta} руб.";
                    }
                    
                    // Добавляем информацию о том, каким ДС было аннулировано или удалено
                    $metadata = $event->metadata ?? [];
                    if (isset($metadata['deleted_by']) || (isset($metadata['reason']) && strpos($metadata['reason'], 'удалено') !== false)) {
                        $description .= " (дополнительное соглашение удалено)";
                    } elseif ($supersedingAgreement) {
                        $description .= " (аннулировано ДС №{$supersedingAgreement->number})";
                    } elseif ($reason) {
                        $description .= " ({$reason})";
                    }
                } else {
                    // Fallback, если связь не загружена
                    $description .= "события ";
                    if ($reason) {
                        $description .= $reason;
                    } else {
                        $description .= "на сумму " . number_format(abs($delta), 2, '.', ' ') . " руб.";
                    }
                    if ($supersedingAgreement) {
                        $description .= " (аннулировано ДС №{$supersedingAgreement->number})";
                    }
                }
                
                return $description;
            case 'cancelled':
                return "Отмена: {$delta} руб.";
            case 'supplementary_agreement_created':
                $agreementNumber = $event->metadata['agreement_number'] ?? null;
                if ($agreementNumber) {
                    return "Создание дополнительного соглашения №{$agreementNumber} на сумму {$delta} руб.";
                }
                return "Создание дополнительного соглашения на сумму {$delta} руб.";
            case 'payment_created':
                $paymentType = $event->metadata['payment_type'] ?? null;
                $paymentTypeLabel = $paymentType === 'advance' ? 'Авансовый' : 'Обычный';
                return "Создание {$paymentTypeLabel} платежа на сумму {$delta} руб.";
            default:
                return "Событие типа {$type}";
        }
    }
}

