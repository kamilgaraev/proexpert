<?php

namespace App\Services\RateCoefficient;

use App\DTOs\RateCoefficient\RateCoefficientDTO;
use App\Models\RateCoefficient;
use App\Repositories\Interfaces\RateCoefficientRepositoryInterface;
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Auth;
use App\Exceptions\BusinessLogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Models\User; // Для getCurrentOrgId
use Illuminate\Support\Facades\Log; // Для getCurrentOrgId

class RateCoefficientService
{
    protected RateCoefficientRepositoryInterface $coefficientRepository;

    public function __construct(RateCoefficientRepositoryInterface $coefficientRepository)
    {
        $this->coefficientRepository = $coefficientRepository;
    }

    protected function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        
        if (!$organizationId) {
            Log::error('Failed to determine organization context in RateCoefficientService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
    }

    public function getAllCoefficients(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $request->only(['name', 'code', 'type', 'applies_to', 'scope', 'is_active', 'valid_from_start', 'valid_from_end', 'valid_to_start', 'valid_to_end']);
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');
        
        // Валидация sortBy, sortDirection (можно вынести в трейт/хелпер)
        $allowedSortBy = ['name', 'code', 'value', 'type', 'applies_to', 'scope', 'is_active', 'valid_from', 'valid_to', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'created_at';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        return $this->coefficientRepository->getCoefficientsForOrganizationPaginated(
            $organizationId,
            $perPage,
            array_filter($filters, fn($value) => $value !== null && $value !== ''),
            $sortBy,
            $sortDirection
        );
    }

    public function createCoefficient(RateCoefficientDTO $dto, Request $request): RateCoefficient
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data = $dto->toArray();
        $data['organization_id'] = $organizationId;

        // Проверка на уникальность code в рамках организации, если code передан
        if (!empty($data['code'])) {
            $existing = $this->coefficientRepository->firstByFilters([
                ['organization_id', '=', $organizationId],
                ['code', '=', $data['code']]
            ]);
            if ($existing) {
                throw new BusinessLogicException('Коэффициент с таким кодом уже существует в вашей организации.', 422);
            }
        }

        $coefficient = $this->coefficientRepository->create($data);
        if (!$coefficient) {
             throw new BusinessLogicException('Не удалось создать коэффициент.', 500);
        }
        return $coefficient;
    }

    public function findCoefficientById(int $id, Request $request): ?RateCoefficient
    {
        $organizationId = $this->getCurrentOrgId($request);
        $coefficient = $this->coefficientRepository->find($id);

        if (!$coefficient || $coefficient->organization_id !== $organizationId) {
            return null;
        }
        return $coefficient;
    }

    public function updateCoefficient(int $id, RateCoefficientDTO $dto, Request $request): RateCoefficient
    {
        $coefficient = $this->findCoefficientById($id, $request);
        if (!$coefficient) {
            throw new BusinessLogicException('Коэффициент не найден или у вас нет прав на его изменение.', 404);
        }

        $data = $dto->toArray();
        // Не позволяем менять organization_id при обновлении
        unset($data['organization_id']); 

        // Проверка на уникальность code в рамках организации при изменении, если code передан и он изменился
        if (!empty($data['code']) && $data['code'] !== $coefficient->code) {
            $existing = $this->coefficientRepository->firstByFilters([
                ['organization_id', '=', $coefficient->organization_id],
                ['code', '=', $data['code']],
                ['id', '!=', $id] // Исключаем текущий коэффициент
            ]);
            if ($existing) {
                throw new BusinessLogicException('Другой коэффициент с таким кодом уже существует в вашей организации.', 422);
            }
        }
        
        $updated = $this->coefficientRepository->update($id, $data);
        if (!$updated) {
            throw new BusinessLogicException('Не удалось обновить коэффициент.', 500);
        }
        return $this->coefficientRepository->find($id); // Возвращаем обновленную модель
    }

    public function deleteCoefficient(int $id, Request $request): bool
    {
        $coefficient = $this->findCoefficientById($id, $request);
        if (!$coefficient) {
            throw new BusinessLogicException('Коэффициент не найден или у вас нет прав на его удаление.', 404);
        }
        return $this->coefficientRepository->delete($id);
    }

    /**
     * Найти применимые коэффициенты для заданного контекста.
     */
    public function getApplicableCoefficients(
        Request $request, 
        string $appliesTo, 
        ?string $scope = null, 
        array $contextualIds = [], 
        ?string $date = null
    ): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);
        return $this->coefficientRepository->findApplicableCoefficients(
            $organizationId,
            $appliesTo,
            $scope,
            $contextualIds,
            $date
        );
    }
    
    // TODO: Возможно, добавить метод для расчета итогового значения с учетом всех применимых коэффициентов
    // public function calculateAdjustedValue(float $originalValue, string $appliesTo, array $context, Request $request): float
    // {
    //     $coefficients = $this->getApplicableCoefficients($request, $appliesTo, null, $context);
    //     $adjustedValue = $originalValue;
    // 
    //     foreach ($coefficients as $coeff) {
    //         if ($coeff->type === RateCoefficientTypeEnum::PERCENTAGE) {
    //             $adjustedValue *= (1 + ($coeff->value / 100)); // Предполагая, что value для процентов хранится как 15 для 15%
    //         } elseif ($coeff->type === RateCoefficientTypeEnum::FIXED_ADDITION) {
    //             $adjustedValue += $coeff->value;
    //         }
    //     }
    //     return round($adjustedValue, 2); // Округляем до 2 знаков
    // }
} 