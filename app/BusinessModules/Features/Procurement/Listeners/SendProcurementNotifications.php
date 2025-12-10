<?php

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated;
use App\BusinessModules\Features\Procurement\Events\PurchaseRequestApproved;
use App\BusinessModules\Features\Procurement\Events\PurchaseOrderSent;
use App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Modules\Core\AccessController;
use App\Models\User;

/**
 * Слушатель для отправки уведомлений по событиям закупок
 */
class SendProcurementNotifications
{
    public function __construct(
        private readonly AccessController $accessController
    ) {}

    /**
     * Уведомление о создании заявки на закупку
     */
    public function handleRequestCreated(PurchaseRequestCreated $event): void
    {
        $request = $event->purchaseRequest;
        
        // Проверяем активацию модуля уведомлений
        if (!$this->accessController->hasModuleAccess($request->organization_id, 'notifications')) {
            return;
        }

        try {
            $notificationService = app(NotificationService::class);

            // Уведомляем менеджера по закупкам (если назначен)
            if ($request->assigned_to) {
                $manager = User::find($request->assigned_to);
                if ($manager) {
                    $notificationService->send(
                        $manager,
                        'procurement.purchase_request.created',
                        [
                            'title' => 'Новая заявка на закупку',
                            'message' => "Создана заявка на закупку #{$request->request_number}",
                            'request_id' => $request->id,
                            'request_number' => $request->request_number,
                            'site_request_id' => $request->site_request_id,
                        ],
                        'procurement',
                        'normal',
                        null,
                        $request->organization_id
                    );
                }
            }

            \Log::info('procurement.notifications.request_created_sent', [
                'request_id' => $request->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.notifications.request_created_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Уведомление об одобрении заявки на закупку
     */
    public function handleRequestApproved(PurchaseRequestApproved $event): void
    {
        $request = $event->purchaseRequest;
        
        if (!$this->accessController->hasModuleAccess($request->organization_id, 'notifications')) {
            return;
        }

        try {
            $notificationService = app(NotificationService::class);

            // Уведомляем создателя заявки с объекта
            if ($request->siteRequest && $request->siteRequest->user) {
                $creator = $request->siteRequest->user;
                $notificationService->send(
                    $creator,
                    'procurement.purchase_request.approved',
                    [
                        'title' => 'Заявка на закупку одобрена',
                        'message' => "Ваша заявка на закупку #{$request->request_number} была одобрена",
                        'request_id' => $request->id,
                        'request_number' => $request->request_number,
                    ],
                    'procurement',
                    'normal',
                    null,
                    $request->organization_id
                );
            }

            \Log::info('procurement.notifications.request_approved_sent', [
                'request_id' => $request->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.notifications.request_approved_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Уведомление об отправке заказа поставщику
     */
    public function handleOrderSent(PurchaseOrderSent $event): void
    {
        $order = $event->purchaseOrder;
        
        if (!$this->accessController->hasModuleAccess($order->organization_id, 'notifications')) {
            return;
        }

        try {
            $notificationService = app(NotificationService::class);

            // Уведомляем менеджера по закупкам
            if ($order->purchaseRequest && $order->purchaseRequest->assigned_to) {
                $manager = User::find($order->purchaseRequest->assigned_to);
                if ($manager) {
                    $notificationService->send(
                        $manager,
                        'procurement.purchase_order.sent',
                        [
                            'title' => 'Заказ отправлен поставщику',
                            'message' => "Заказ #{$order->order_number} отправлен поставщику {$order->supplier->name}",
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'supplier_name' => $order->supplier->name,
                            'total_amount' => $order->total_amount,
                            'currency' => $order->currency,
                        ],
                        'procurement',
                        'normal',
                        null,
                        $order->organization_id
                    );
                }
            }

            \Log::info('procurement.notifications.order_sent', [
                'order_id' => $order->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.notifications.order_sent_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Уведомление о получении материалов
     */
    public function handleMaterialsReceived(MaterialReceivedFromSupplier $event): void
    {
        $order = $event->purchaseOrder;
        
        if (!$this->accessController->hasModuleAccess($order->organization_id, 'notifications')) {
            return;
        }

        try {
            $notificationService = app(NotificationService::class);

            // Уведомляем создателя заявки с объекта
            if ($order->purchaseRequest && $order->purchaseRequest->siteRequest && $order->purchaseRequest->siteRequest->user) {
                $creator = $order->purchaseRequest->siteRequest->user;
                $notificationService->send(
                    $creator,
                    'procurement.materials.received',
                    [
                        'title' => 'Материалы получены',
                        'message' => "Материалы по вашей заявке получены на склад",
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'warehouse_id' => $event->warehouseId,
                        'items_count' => count($event->items),
                    ],
                    'procurement',
                    'high',
                    null,
                    $order->organization_id
                );
            }

            // Уведомляем проектного менеджера
            if ($order->purchaseRequest && $order->purchaseRequest->siteRequest && $order->purchaseRequest->siteRequest->project) {
                $project = $order->purchaseRequest->siteRequest->project;
                // Предполагаем что у проекта есть менеджер
                if ($project->manager_id) {
                    $projectManager = User::find($project->manager_id);
                    if ($projectManager) {
                        $notificationService->send(
                            $projectManager,
                            'procurement.materials.received',
                            [
                                'title' => 'Материалы для проекта получены',
                                'message' => "Материалы для проекта {$project->name} получены на склад",
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'project_id' => $project->id,
                                'project_name' => $project->name,
                                'warehouse_id' => $event->warehouseId,
                            ],
                            'procurement',
                            'high',
                            null,
                            $order->organization_id
                        );
                    }
                }
            }

            \Log::info('procurement.notifications.materials_received_sent', [
                'order_id' => $order->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.notifications.materials_received_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

