<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestPaymentCreated;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Contractor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiteRequestPaymentService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService
    ) {}

    /**
     * Получить список заявок, доступных для создания платежей
     *
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (project_id, request_type, search и т.д.)
     * @return Collection
     */
    public function getAvailableForPayment(int $organizationId, array $filters = []): Collection
    {
        $query = SiteRequest::query()
            ->forOrganization($organizationId)
            ->withStatus(SiteRequestStatusEnum::APPROVED)
            ->whereDoesntHave('paymentDocuments');

        // Фильтр по проекту
        if (isset($filters['project_id'])) {
            $query->forProject($filters['project_id']);
        }

        // Фильтр по типу заявки
        if (isset($filters['request_type'])) {
            $query->ofType($filters['request_type']);
        }

        // Поиск по названию/описанию
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($search) . '%'])
                  ->orWhereRaw('LOWER(description) LIKE ?', ['%' . mb_strtolower($search) . '%']);
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Валидация заявок перед созданием платежа
     *
     * @param array $requestIds Массив ID заявок
     * @param int $organizationId ID организации
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function validateRequestsForPayment(array $requestIds, int $organizationId): void
    {
        if (empty($requestIds)) {
            throw new \InvalidArgumentException('Необходимо выбрать хотя бы одну заявку');
        }

        // Получаем заявки
        $requests = SiteRequest::whereIn('id', $requestIds)
            ->forOrganization($organizationId)
            ->get();

        // Проверяем, что все заявки найдены
        if ($requests->count() !== count($requestIds)) {
            $foundIds = $requests->pluck('id')->toArray();
            $missingIds = array_diff($requestIds, $foundIds);
            throw new \InvalidArgumentException(
                "Заявки с ID " . implode(', ', $missingIds) . " не найдены или не принадлежат организации"
            );
        }

        // Проверяем статус заявок
        $invalidStatusRequests = $requests->filter(function ($request) {
            return $request->status !== SiteRequestStatusEnum::APPROVED;
        });

        if ($invalidStatusRequests->isNotEmpty()) {
            $invalidIds = $invalidStatusRequests->pluck('id')->toArray();
            throw new \DomainException(
                "Заявки с ID " . implode(', ', $invalidIds) . " не находятся в статусе 'Одобрена'"
            );
        }

        // Проверяем, что заявки не связаны с другими платежами
        $alreadyLinkedRequests = $requests->filter(function ($request) {
            return $request->hasPaymentDocument();
        });

        if ($alreadyLinkedRequests->isNotEmpty()) {
            $linkedIds = $alreadyLinkedRequests->pluck('id')->toArray();
            throw new \DomainException(
                "Заявки с ID " . implode(', ', $linkedIds) . " уже связаны с платежами"
            );
        }

        // Проверяем, что все заявки из одной организации (дополнительная проверка)
        $differentOrgRequests = $requests->filter(function ($request) use ($organizationId) {
            return $request->organization_id !== $organizationId;
        });

        if ($differentOrgRequests->isNotEmpty()) {
            throw new \DomainException('Все заявки должны принадлежать одной организации');
        }
    }

    /**
     * Создать платеж из заявок
     *
     * @param int $organizationId ID организации
     * @param array $requestIds Массив ID заявок
     * @param array $paymentData Данные платежа
     * @return PaymentDocument
     * @throws \Exception
     */
    public function createPaymentFromRequests(int $organizationId, array $requestIds, array $paymentData): PaymentDocument
    {
        // Валидация заявок
        $this->validateRequestsForPayment($requestIds, $organizationId);

        // Получаем заявки
        $requests = SiteRequest::whereIn('id', $requestIds)
            ->forOrganization($organizationId)
            ->get();

        // Валидация подрядчика
        if (empty($paymentData['payee_contractor_id'])) {
            throw new \InvalidArgumentException('Необходимо указать подрядчика-получателя');
        }

        $contractor = Contractor::where('id', $paymentData['payee_contractor_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$contractor) {
            throw new \InvalidArgumentException('Подрядчик не найден или не принадлежит организации');
        }

        // Определяем проект (если все заявки из одного проекта)
        $projectIds = $requests->pluck('project_id')->unique()->filter()->values();
        $projectId = $projectIds->count() === 1 ? $projectIds->first() : null;

        // Формируем описание платежа из заявок
        $description = $paymentData['description'] ?? $this->generateDescriptionFromRequests($requests);

        // Формируем данные для создания платежного документа
        $documentData = [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => $paymentData['document_date'] ?? now()->toDateString(),
            'payer_organization_id' => $organizationId,
            'payee_contractor_id' => $paymentData['payee_contractor_id'],
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'RUB',
            'vat_rate' => $paymentData['vat_rate'] ?? 20,
            'description' => $description,
            'payment_purpose' => $paymentData['payment_purpose'] ?? $this->generatePaymentPurpose($requests),
            'due_date' => $paymentData['due_date'] ?? null,
            'payment_terms_days' => $paymentData['payment_terms_days'] ?? null,
            'created_by_user_id' => $paymentData['created_by_user_id'] ?? auth()->id(),
            'status' => 'draft',
        ];

        DB::beginTransaction();

        try {
            // Создаем платежный документ
            $paymentDocument = $this->paymentDocumentService->create($documentData);

            // Связываем заявки с платежом
            $pivotData = [];
            foreach ($requests as $request) {
                $pivotData[$request->id] = [
                    'amount' => null, // Можно распределить сумму по заявкам, но пока оставляем null
                ];
            }

            $paymentDocument->siteRequests()->attach($pivotData);

            // Если платеж создан из одной заявки, устанавливаем прямую связь для быстрого доступа
            if (count($requestIds) === 1) {
                $requests->first()->update(['payment_document_id' => $paymentDocument->id]);
            }

            Log::info('site_request.payment.created', [
                'organization_id' => $organizationId,
                'payment_document_id' => $paymentDocument->id,
                'site_request_ids' => $requestIds,
                'amount' => $paymentData['amount'],
            ]);

            // Генерируем событие
            event(new SiteRequestPaymentCreated(
                $paymentDocument,
                $requests,
                $paymentData['created_by_user_id'] ?? auth()->id()
            ));

            DB::commit();

            // Загружаем связи для возврата
            $paymentDocument->load('siteRequests');

            return $paymentDocument;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('site_request.payment.create_failed', [
                'organization_id' => $organizationId,
                'request_ids' => $requestIds,
                'payment_data' => $paymentData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Сгенерировать описание платежа из заявок
     *
     * @param Collection $requests Коллекция заявок
     * @return string
     */
    private function generateDescriptionFromRequests(Collection $requests): string
    {
        if ($requests->count() === 1) {
            $request = $requests->first();
            return "Оплата по заявке: {$request->title}";
        }

        $titles = $requests->pluck('title')->take(3)->toArray();
        $count = $requests->count();

        if ($count <= 3) {
            return "Оплата по заявкам: " . implode(', ', $titles);
        }

        return "Оплата по {$count} заявкам: " . implode(', ', $titles) . " и еще " . ($count - 3);
    }

    /**
     * Сгенерировать назначение платежа из заявок
     *
     * @param Collection $requests Коллекция заявок
     * @return string
     */
    private function generatePaymentPurpose(Collection $requests): string
    {
        $purposes = [];

        foreach ($requests as $request) {
            $typeLabel = $request->request_type->label();
            $purpose = "Оплата {$typeLabel}";

            if ($request->request_type->value === 'personnel_request' && $request->estimated_personnel_cost) {
                $purpose .= " (расчетная стоимость: " . number_format($request->estimated_personnel_cost, 2, '.', ' ') . " руб.)";
            }

            $purposes[] = $purpose;
        }

        return implode('; ', $purposes);
    }
}

