<?php

declare(strict_types=1);

namespace App\Services\RateCoefficient;

use App\DTOs\RateCoefficient\RateCoefficientDTO;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Exceptions\BusinessLogicException;
use App\Models\RateCoefficient;
use App\Models\User;
use App\Repositories\Interfaces\RateCoefficientRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RateCoefficientService
{
    public function __construct(
        protected RateCoefficientRepositoryInterface $coefficientRepository
    ) {
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
            Log::error('Failed to determine organization context in RateCoefficientService', [
                'user_id' => $user?->id,
                'request_attributes' => $request->attributes->all(),
            ]);

            throw new BusinessLogicException(trans_message('rate_coefficients.organization_not_found'), 500);
        }

        return (int) $organizationId;
    }

    public function getAllCoefficients(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $request->only([
            'name',
            'code',
            'type',
            'applies_to',
            'scope',
            'is_active',
            'valid_from_start',
            'valid_from_end',
            'valid_to_start',
            'valid_to_end',
        ]);
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        $allowedSortBy = [
            'name',
            'code',
            'value',
            'type',
            'applies_to',
            'scope',
            'is_active',
            'valid_from',
            'valid_to',
            'created_at',
            'updated_at',
        ];

        if (!in_array(strtolower((string) $sortBy), $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array(strtolower((string) $sortDirection), ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        return $this->coefficientRepository->getCoefficientsForOrganizationPaginated(
            $organizationId,
            $perPage,
            array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            (string) $sortBy,
            (string) $sortDirection
        );
    }

    public function createCoefficient(RateCoefficientDTO $dto, Request $request): RateCoefficient
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data = $dto->toArray();
        $data['organization_id'] = $organizationId;

        if (!empty($data['code'])) {
            $existing = $this->coefficientRepository->firstByFilters([
                ['organization_id', '=', $organizationId],
                ['code', '=', $data['code']],
            ]);

            if ($existing) {
                throw new BusinessLogicException(trans_message('rate_coefficients.duplicate_code'), 422);
            }
        }

        $coefficient = $this->coefficientRepository->create($data);

        if (!$coefficient) {
            throw new BusinessLogicException(trans_message('rate_coefficients.create_error'), 500);
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
            throw new BusinessLogicException(trans_message('rate_coefficients.not_found'), 404);
        }

        $data = $dto->toArray();
        unset($data['organization_id']);

        if (!empty($data['code']) && $data['code'] !== $coefficient->code) {
            $existing = $this->coefficientRepository->firstByFilters([
                ['organization_id', '=', $coefficient->organization_id],
                ['code', '=', $data['code']],
                ['id', '!=', $id],
            ]);

            if ($existing) {
                throw new BusinessLogicException(trans_message('rate_coefficients.duplicate_code'), 422);
            }
        }

        $updated = $this->coefficientRepository->update($id, $data);

        if (!$updated) {
            throw new BusinessLogicException(trans_message('rate_coefficients.update_error'), 500);
        }

        return $this->coefficientRepository->find($id);
    }

    public function deleteCoefficient(int $id, Request $request): bool
    {
        $coefficient = $this->findCoefficientById($id, $request);

        if (!$coefficient) {
            throw new BusinessLogicException(trans_message('rate_coefficients.not_found'), 404);
        }

        return $this->coefficientRepository->delete($id);
    }

    public function getApplicableCoefficients(
        Request $request,
        string $appliesTo,
        ?string $scope = null,
        array $contextualIds = [],
        ?string $date = null
    ): Collection {
        $organizationId = $this->getCurrentOrgId($request);

        return $this->coefficientRepository->findApplicableCoefficients(
            $organizationId,
            $appliesTo,
            $scope,
            $contextualIds,
            $date
        );
    }

    public function calculateAdjustedValue(
        int $organizationId,
        float $originalValue,
        string $appliesTo,
        ?string $scope = null,
        array $contextualIds = [],
        ?string $date = null
    ): float {
        return $this->calculateAdjustedValueDetailed(
            $organizationId,
            $originalValue,
            $appliesTo,
            $scope,
            $contextualIds,
            $date
        )['final'];
    }

    public function calculateAdjustedValueDetailed(
        int $organizationId,
        float $originalValue,
        string $appliesTo,
        ?string $scope = null,
        array $contextualIds = [],
        ?string $date = null
    ): array {
        $coefficients = $this->coefficientRepository->findApplicableCoefficients(
            $organizationId,
            $appliesTo,
            $scope,
            $contextualIds,
            $date
        );

        $adjusted = $originalValue;
        $applications = [];

        foreach ($coefficients as $coeff) {
            $before = $adjusted;

            if ($coeff->type === RateCoefficientTypeEnum::PERCENTAGE) {
                $adjusted *= (1 + ((float) $coeff->value / 100));
            } elseif ($coeff->type === RateCoefficientTypeEnum::FIXED_ADDITION) {
                $adjusted += (float) $coeff->value;
            }

            $applications[] = [
                'id' => $coeff->id,
                'name' => $coeff->name,
                'type' => $coeff->type->value,
                'value' => (float) $coeff->value,
                'before' => round($before, 4),
                'after' => round($adjusted, 4),
            ];
        }

        return [
            'original' => round($originalValue, 4),
            'final' => round($adjusted, 4),
            'applications' => $applications,
        ];
    }
}
