<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestTemplate;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Сервис для работы с шаблонами заявок
 */
class SiteRequestTemplateService
{
    public function __construct(
        private readonly SiteRequestService $requestService,
        private readonly SiteRequestsModule $module
    ) {}

    /**
     * Получить шаблон по ID
     */
    public function find(int $id, int $organizationId): ?SiteRequestTemplate
    {
        return SiteRequestTemplate::forOrganization($organizationId)
            ->with('user')
            ->find($id);
    }

    /**
     * Получить список шаблонов с пагинацией
     */
    public function paginate(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = SiteRequestTemplate::forOrganization($organizationId)
            ->with('user');

        if (!empty($filters['request_type'])) {
            $query->ofType($filters['request_type']);
        }

        if (isset($filters['is_active'])) {
            if ($filters['is_active']) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        if (!empty($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        return $query->orderBy('usage_count', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Получить популярные шаблоны
     */
    public function getPopularTemplates(int $organizationId, int $limit = 10): Collection
    {
        return SiteRequestTemplate::forOrganization($organizationId)
            ->active()
            ->popular()
            ->with('user')
            ->limit($limit)
            ->get();
    }

    /**
     * Получить шаблоны по типу
     */
    public function getByType(int $organizationId, SiteRequestTypeEnum $type): Collection
    {
        return SiteRequestTemplate::forOrganization($organizationId)
            ->active()
            ->ofType($type)
            ->orderBy('usage_count', 'desc')
            ->get();
    }

    /**
     * Создать шаблон
     */
    public function create(int $organizationId, int $userId, array $data): SiteRequestTemplate
    {
        // Проверяем лимит шаблонов
        $this->checkTemplateLimit($organizationId);

        return SiteRequestTemplate::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'request_type' => $data['request_type'],
            'template_data' => $data['template_data'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Обновить шаблон
     */
    public function update(SiteRequestTemplate $template, array $data): SiteRequestTemplate
    {
        $template->update($data);
        return $template->fresh();
    }

    /**
     * Удалить шаблон
     */
    public function delete(SiteRequestTemplate $template): bool
    {
        return $template->delete();
    }

    /**
     * Сохранить заявку как шаблон
     */
    public function saveAsTemplate(
        SiteRequest $request,
        int $userId,
        string $name,
        ?string $description = null
    ): SiteRequestTemplate {
        // Проверяем лимит шаблонов
        $this->checkTemplateLimit($request->organization_id);

        // Подготавливаем данные шаблона
        $templateData = $this->extractTemplateData($request);

        return SiteRequestTemplate::create([
            'organization_id' => $request->organization_id,
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'request_type' => $request->request_type->value,
            'template_data' => $templateData,
            'is_active' => true,
        ]);
    }

    /**
     * Создать заявку из шаблона
     */
    public function createFromTemplate(
        int $templateId,
        int $organizationId,
        int $userId,
        int $projectId,
        array $overrides = []
    ): SiteRequest {
        $template = $this->find($templateId, $organizationId);

        if (!$template) {
            throw new \InvalidArgumentException('Шаблон не найден');
        }

        if (!$template->is_active) {
            throw new \DomainException('Шаблон неактивен');
        }

        // Получаем данные из шаблона
        $data = array_merge(
            $template->getRequestData(),
            [
                'project_id' => $projectId,
                'template_id' => $template->id,
            ],
            $overrides
        );

        // Создаем заявку
        $request = $this->requestService->create($organizationId, $userId, $data);

        // Увеличиваем счетчик использования
        $template->incrementUsage();

        return $request;
    }

    /**
     * Извлечь данные для шаблона из заявки
     */
    private function extractTemplateData(SiteRequest $request): array
    {
        $fields = [
            'title',
            'description',
            'priority',
            'request_type',
            // Материалы
            'material_name',
            'material_quantity',
            'material_unit',
            'delivery_address',
            'contact_person_name',
            'contact_person_phone',
            // Персонал
            'personnel_type',
            'personnel_count',
            'personnel_requirements',
            'hourly_rate',
            'work_hours_per_day',
            'work_location',
            'additional_conditions',
            // Техника
            'equipment_type',
            'equipment_specs',
            'rental_hours_per_day',
            'with_operator',
            'equipment_location',
            // Метаданные
            'metadata',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $request->$field;
            if ($value !== null) {
                // Конвертируем enum в строку
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * Проверить лимит шаблонов
     */
    private function checkTemplateLimit(int $organizationId): void
    {
        $settings = $this->module->getSettings($organizationId);
        $maxTemplates = $settings['max_templates'] ?? 20;

        $currentCount = SiteRequestTemplate::forOrganization($organizationId)->count();

        if ($currentCount >= $maxTemplates) {
            throw new \DomainException("Достигнут лимит шаблонов: {$maxTemplates}");
        }
    }
}

