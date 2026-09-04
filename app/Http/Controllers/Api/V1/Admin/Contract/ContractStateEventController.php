<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Services\Contract\ContractService;
use App\Services\Contract\ContractAgreementEventFormatter;
use App\Services\Contract\ContractStateCalculatorService;
use App\Services\Contract\ContractStateEventService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ContractStateEventController extends Controller
{
    protected ContractService $contractService;

    protected ContractStateEventService $stateEventService;

    protected ContractStateCalculatorService $stateCalculatorService;

    protected ContractPerformanceActRepositoryInterface $performanceActRepository;

    public function __construct(
        ContractService $contractService,
        ContractStateEventService $stateEventService,
        ContractStateCalculatorService $stateCalculatorService,
        ContractPerformanceActRepositoryInterface $performanceActRepository
    ) {
        $this->contractService = $contractService;
        $this->stateEventService = $stateEventService;
        $this->stateCalculatorService = $stateCalculatorService;
        $this->performanceActRepository = $performanceActRepository;
    }

    private function validateProjectContext(Request $request, $contract): bool
    {
        $projectId = $request->route('project');

        if (! $projectId) {
            return true;
        }

        if ($contract->is_multi_project) {
            return $contract->projects()->where('projects.id', $projectId)->exists();
        }

        if ((int) $contract->project_id !== (int) $projectId) {
            return false;
        }

        return true;
    }

    /**
     * Получить историю событий для договора
     */
    public function index(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;

        if (! $organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);

            if (! $contractModel) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }

            if (! $this->validateProjectContext($request, $contractModel)) {
                return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
            }

            if (! $contractModel->usesEventSourcing()) {
                return AdminResponse::success([], trans_message('contract.legacy_unavailable'));
            }

            $timeline = $this->stateEventService->getTimeline($contractModel);

            return AdminResponse::success(
                $timeline->map(function ($event) {
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
                            'name' => $event->createdBy->name ?? 'System',
                        ] : null,
                        'specification' => $event->specification ? [
                            'id' => $event->specification->id,
                            'number' => $event->specification->number,
                            'total_amount' => $event->specification->total_amount,
                        ] : null,
                        'is_active' => $event->isActive(),
                    ];
                })
            );
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.timeline_error').': '.$e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить timeline событий с деталями
     */
    public function timeline(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $asOfDate = $request->query('as_of_date') ? Carbon::parse($request->query('as_of_date')) : null;

        if (! $organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);

            if (! $contractModel) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }

            if (! $this->validateProjectContext($request, $contractModel)) {
                return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
            }

            if (! $contractModel->usesEventSourcing()) {
                return AdminResponse::success([], trans_message('contract.legacy_unavailable'));
            }

            // Получаем события
            $timeline = $this->stateEventService->getTimeline($contractModel, $asOfDate);

            // Получаем акты
            $performanceActs = $this->performanceActRepository->getActsForContract($contractModel->id);
            if ($performanceActs instanceof EloquentCollection) {
                $performanceActs->loadMissing('createdBy');
            }

            // Фильтруем акты по дате если указано
            if ($asOfDate) {
                $performanceActs = $performanceActs->filter(function ($act) use ($asOfDate) {
                    return $act->created_at <= $asOfDate;
                });
            }

            // Формируем массив событий
            $events = $timeline->map(function ($event) use ($request) {
                return [
                    'type' => 'event',
                    'id' => $event->id,
                    'event_type' => $event->event_type->value,
                    'event_type_label' => $this->getEventTypeLabel($event->event_type->value),
                    'description' => $this->getEventDescription($event),
                    'amount_delta' => $event->amount_delta,
                    'effective_from' => $event->effective_from?->format('Y-m-d'),
                    'created_at' => $event->created_at?->toIso8601String(),
                    'created_by' => $this->formatActor(
                        $event->created_by_user_id,
                        $event->createdBy?->name,
                        $request->user()?->id
                    ),
                    'specification' => $event->specification ? [
                        'id' => $event->specification->id,
                        'number' => $event->specification->number,
                    ] : null,
                    'is_active' => $event->isActive(),
                    'sort_date' => $event->created_at,
                ];
            })->toArray();

            // Формируем массив актов
            $acts = $performanceActs->map(function ($act) use ($request) {
                return [
                    'type' => 'performance_act',
                    'id' => $act->id,
                    'event_type' => 'performance_act',
                    'event_type_label' => $this->getEventTypeLabel('performance_act'),
                    'description' => "Акт выполненных работ №{$act->act_document_number} на сумму ".$this->formatMoney($act->amount),
                    'amount_delta' => $act->amount,
                    'effective_from' => $act->act_date?->format('Y-m-d'),
                    'created_at' => $act->created_at?->toIso8601String(),
                    'created_by' => $this->formatActor(
                        $act->created_by_user_id,
                        $act->createdBy?->name,
                        $request->user()?->id
                    ),
                    'act_document_number' => $act->act_document_number,
                    'is_approved' => $act->is_approved,
                    'approval_date' => $act->approval_date?->format('Y-m-d'),
                    'is_active' => true,
                    'sort_date' => $act->created_at,
                ];
            })->toArray();

            // Объединяем и сортируем по дате
            $combined = array_merge($events, $acts);
            usort($combined, function ($a, $b) {
                return $a['sort_date'] <=> $b['sort_date'];
            });

            // Убираем служебное поле sort_date
            $combined = array_map(function ($item) {
                unset($item['sort_date']);

                return $item;
            }, $combined);

            return AdminResponse::success([
                'contract_id' => $contractModel->id,
                'as_of_date' => $asOfDate?->format('Y-m-d'),
                'events' => $combined,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to build contract timeline', [
                'organization_id' => $organizationId,
                'project_id' => $project,
                'contract_id' => $contract,
                'exception' => $e::class,
            ]);

            return AdminResponse::error(trans_message('contract.timeline_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    private function getEventTypeLabel(string $eventType): string
    {
        $key = "contract.timeline.event_types.{$eventType}";
        $label = trans_message($key);

        return $label === $key
            ? trans_message('contract.timeline.event_types.unknown')
            : $label;
    }

    private function formatActor(?int $actorId, ?string $actorName, ?int $viewerId): string
    {
        if ($actorId !== null && $actorId === $viewerId) {
            return trans_message('contract.timeline.current_actor');
        }

        return $actorName ?: trans_message('contract.timeline.system_actor');
    }

    private function formatMoney(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' ₽';
    }

    private function formatSignedMoney(float|int|string|null $amount): string
    {
        $numericAmount = (float) $amount;

        return ($numericAmount >= 0 ? '+' : '').$this->formatMoney($numericAmount);
    }

    /**
     * Получить текущее состояние договора
     */
    public function currentState(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;

        if (! $organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);

            if (! $contractModel) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }

            if (! $this->validateProjectContext($request, $contractModel)) {
                return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
            }

            if (! $contractModel->usesEventSourcing()) {
                // Для legacy договоров возвращаем простое состояние
                return AdminResponse::success([
                    'contract_id' => $contractModel->id,
                    'uses_event_sourcing' => false,
                    'total_amount' => $contractModel->total_amount,
                    'active_specification' => $contractModel->specifications()->first(),
                    'message' => trans_message('contract.legacy_event_sourcing_unavailable'),
                    'activation_hint' => trans_message('contract.event_sourcing_activation_hint'),
                ]);
            }

            $state = $this->stateEventService->getCurrentState($contractModel);

            return AdminResponse::success([
                'contract_id' => $state['contract_id'],
                'total_amount' => $state['total_amount'],
                'active_specification' => $state['active_specification'] ? [
                    'id' => $state['active_specification']->id,
                    'number' => $state['active_specification']->number,
                    'total_amount' => $state['active_specification']->total_amount,
                ] : null,
                'active_events_count' => $state['active_events']->count(),
                'as_of_date' => $state['as_of_date']->format('Y-m-d'),
            ]);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.state_error').': '.$e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить состояние договора на определенную дату
     */
    public function stateAtDate(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $asOfDate = $request->query('as_of_date') ? Carbon::parse($request->query('as_of_date')) : null;

        if (! $organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        if (! $asOfDate) {
            return AdminResponse::error(trans_message('contract.state_date_required'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractModel = $this->contractService->getContractById($contract, $organizationId);

            if (! $contractModel) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }

            if (! $this->validateProjectContext($request, $contractModel)) {
                return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
            }

            if (! $contractModel->usesEventSourcing()) {
                return AdminResponse::error(trans_message('contract.legacy_unavailable'), Response::HTTP_BAD_REQUEST);
            }

            $state = $this->stateEventService->getStateAtDate($contractModel, $asOfDate);

            return AdminResponse::success([
                'contract_id' => $state['contract_id'],
                'total_amount' => $state['total_amount'],
                'active_specification' => $state['active_specification'] ? [
                    'id' => $state['active_specification']->id,
                    'number' => $state['active_specification']->number,
                    'total_amount' => $state['active_specification']->total_amount,
                ] : null,
                'active_events_count' => $state['active_events']->count(),
                'as_of_date' => $state['as_of_date']->format('Y-m-d'),
            ]);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.state_error').': '.$e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить описательное название события
     */
    private function getEventDescription($event): string
    {
        $agreementDescription = app(ContractAgreementEventFormatter::class)->format($event);
        if ($agreementDescription !== null) {
            return $agreementDescription;
        }

        $type = $event->event_type->value;
        $delta = $this->formatMoney($event->amount_delta);

        switch ($type) {
            case 'created':
                return "Создание договора на сумму {$delta}";
            case 'amended':
                $agreementNumber = $event->metadata['agreement_number'] ?? null;
                $reason = $event->metadata['reason'] ?? null;
                $triggeredBy = $event->metadata['triggered_by'] ?? null;

                // Автоматический пересчет из-за акта выполненных работ
                if ($triggeredBy === 'performance_act') {
                    $actNumber = $event->metadata['act_document_number'] ?? null;
                    $oldAmount = $event->metadata['old_total_amount'] ?? null;
                    $newAmount = $event->metadata['new_total_amount'] ?? null;

                    if ($actNumber) {
                        $formattedDelta = $this->formatSignedMoney($event->amount_delta);
                        if ($oldAmount !== null && $newAmount !== null) {
                            $formattedOldAmount = $this->formatMoney($oldAmount);
                            $formattedNewAmount = $this->formatMoney($newAmount);

                            return "Акт №{$actNumber}: {$formattedOldAmount} → {$formattedNewAmount} ({$formattedDelta})";
                        }

                        return "Акт №{$actNumber}: {$formattedDelta}";
                    }
                    $formattedDelta = $this->formatSignedMoney($event->amount_delta);

                    return "Акт выполненных работ: {$formattedDelta}";
                }

                // Автоматический пересчет из-за дополнительного соглашения
                if ($triggeredBy === 'supplementary_agreement') {
                    $oldAmount = $event->metadata['old_total_amount'] ?? null;
                    $newAmount = $event->metadata['new_total_amount'] ?? null;

                    if ($agreementNumber) {
                        $formattedDelta = $this->formatSignedMoney($event->amount_delta);
                        if ($oldAmount !== null && $newAmount !== null) {
                            $formattedOldAmount = $this->formatMoney($oldAmount);
                            $formattedNewAmount = $this->formatMoney($newAmount);

                            return "ДС №{$agreementNumber}: {$formattedOldAmount} → {$formattedNewAmount} ({$formattedDelta})";
                        }

                        return "ДС №{$agreementNumber}: {$formattedDelta}";
                    }
                    $formattedDelta = $this->formatSignedMoney($event->amount_delta);

                    return "Дополнительное соглашение: {$formattedDelta}";
                }

                // Обычное дополнительное соглашение
                if ($agreementNumber) {
                    return "Создание дополнительного соглашения №{$agreementNumber} на сумму {$delta}";
                }

                // Изменение суммы контракта вручную
                if ($reason === 'Изменение суммы контракта') {
                    $oldAmount = $event->metadata['old_amount'] ?? null;
                    $newAmount = $event->metadata['new_amount'] ?? null;
                    if ($oldAmount !== null && $newAmount !== null) {
                        $formattedOldAmount = $this->formatMoney($oldAmount);
                        $formattedNewAmount = $this->formatMoney($newAmount);
                        $formattedDelta = $this->formatSignedMoney($event->amount_delta);

                        return "Изменение договора: {$formattedOldAmount} → {$formattedNewAmount} ({$formattedDelta})";
                    }
                    $formattedDelta = $this->formatSignedMoney($event->amount_delta);

                    return "Изменение договора: {$formattedDelta}";
                }

                return "Изменение договора: +{$delta}";
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

                $description = 'Аннулирование ';

                if ($supersedesEvent) {
                    // Определяем тип аннулированного события
                    $supersededType = $supersedesEvent->event_type->value;
                    $supersededDelta = $this->formatMoney(abs((float) $supersedesEvent->amount_delta));

                    if ($supersededType === 'supplementary_agreement_created' || $supersededType === 'amended') {
                        // Пытаемся получить номер из metadata или из связанного ДС
                        $supersededAgreementNum = $supersedesEvent->metadata['agreement_number'] ?? null;
                        if (! $supersededAgreementNum && $supersedesEvent->triggered_by_id) {
                            $supersededAgreementModel = \App\Models\SupplementaryAgreement::find($supersedesEvent->triggered_by_id);
                            $supersededAgreementNum = $supersededAgreementModel?->number;
                        }

                        if ($supersededAgreementNum) {
                            $description .= "дополнительного соглашения №{$supersededAgreementNum} ";
                        } else {
                            $description .= 'дополнительного соглашения ';
                        }

                        $description .= "на сумму {$supersededDelta}";
                    } elseif ($supersededType === 'created') {
                        $description .= "создания договора на сумму {$supersededDelta}";
                    } else {
                        $description .= "события типа '{$supersededType}' на сумму {$supersededDelta}";
                    }

                    // Добавляем информацию о том, каким ДС было аннулировано или удалено
                    $metadata = $event->metadata ?? [];
                    if (isset($metadata['deleted_by']) || (isset($metadata['reason']) && strpos($metadata['reason'], 'удалено') !== false)) {
                        $description .= ' (дополнительное соглашение удалено)';
                    } elseif ($supersedingAgreement) {
                        $description .= " (аннулировано ДС №{$supersedingAgreement->number})";
                    } elseif ($reason) {
                        $description .= " ({$reason})";
                    }
                } else {
                    // Fallback, если связь не загружена
                    $description .= 'события ';
                    if ($reason) {
                        $description .= $reason;
                    } else {
                        $description .= 'на сумму '.$this->formatMoney(abs((float) $event->amount_delta));
                    }
                    if ($supersedingAgreement) {
                        $description .= " (аннулировано ДС №{$supersedingAgreement->number})";
                    }
                }

                return $description;
            case 'cancelled':
                return "Отмена: {$delta}";
            case 'supplementary_agreement_created':
                $agreementNumber = $event->metadata['agreement_number'] ?? null;
                if ($agreementNumber) {
                    return "Создание дополнительного соглашения №{$agreementNumber} на сумму {$delta}";
                }

                return "Создание дополнительного соглашения на сумму {$delta}";
            case 'payment_created':
                $paymentType = $event->metadata['payment_type'] ?? null;
                $paymentTypeLabel = $paymentType === 'advance' ? 'Авансовый' : 'Обычный';

                return "Создание {$paymentTypeLabel} платежа на сумму {$delta}";
            case 'status_transition':
                $metadata = $event->metadata ?? [];
                $reason = trim((string) ($metadata['reason'] ?? ''));

                return trans_message('contracts.lifecycle.description', [
                    'action' => trans_message('contracts.lifecycle.actions.'.($metadata['action'] ?? 'change')),
                    'from' => trans_message('contract.statuses.'.($metadata['from_status'] ?? 'draft')),
                    'to' => trans_message('contract.statuses.'.($metadata['to_status'] ?? 'draft')),
                    'reason' => $reason !== ''
                        ? trans_message('contracts.lifecycle.reason', ['reason' => $reason])
                        : '',
                ]);
            default:
                return "Событие типа {$type}";
        }
    }
}
