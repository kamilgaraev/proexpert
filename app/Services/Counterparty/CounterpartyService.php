<?php

declare(strict_types=1);

namespace App\Services\Counterparty;

use App\DTOs\Counterparty\CounterpartyData;
use App\Exceptions\BusinessLogicException;
use App\Models\Counterparty;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class CounterpartyService
{
    private const DEFAULT_SORT_FIELD = 'name';
    private const DEFAULT_SORT_DIRECTION = 'asc';
    private const SORTABLE_FIELDS = ['id', 'name', 'inn', 'created_at', 'updated_at'];

    public function paginate(
        int $organizationId,
        int $perPage,
        array $filters = [],
        string $sortBy = self::DEFAULT_SORT_FIELD,
        string $sortDirection = self::DEFAULT_SORT_DIRECTION
    ): LengthAwarePaginator {
        $query = Counterparty::query()
            ->with('linkedOrganization')
            ->where('organization_id', $organizationId);

        $this->applyFilters($query, $filters);

        return $query
            ->orderBy($this->normalizeSortField($sortBy), $this->normalizeSortDirection($sortDirection))
            ->paginate($perPage);
    }

    public function search(int $organizationId, array $filters = [], int $limit = 20): Collection
    {
        $query = Counterparty::query()
            ->with('linkedOrganization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true);

        $this->applyFilters($query, $filters);

        return $query
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    public function create(int $organizationId, CounterpartyData $data): Counterparty
    {
        $payload = $data->toArray();
        $payload['organization_id'] = $organizationId;

        $this->ensureInnKppIsUnique(
            $organizationId,
            $payload['inn'] ?? null,
            $payload['kpp'] ?? null
        );

        return Counterparty::query()
            ->create($payload)
            ->load('linkedOrganization');
    }

    public function getById(int $counterpartyId, int $organizationId): ?Counterparty
    {
        return Counterparty::query()
            ->with('linkedOrganization')
            ->where('organization_id', $organizationId)
            ->whereKey($counterpartyId)
            ->first();
    }

    public function update(int $counterpartyId, int $organizationId, CounterpartyData $data): Counterparty
    {
        $counterparty = $this->getById($counterpartyId, $organizationId);

        if (!$counterparty) {
            throw new BusinessLogicException(trans_message('counterparty.not_found'), Response::HTTP_NOT_FOUND);
        }

        $payload = $data->toArray();

        $this->ensureInnKppIsUnique(
            $organizationId,
            $payload['inn'] ?? $counterparty->inn,
            $payload['kpp'] ?? $counterparty->kpp,
            $counterpartyId
        );

        $counterparty->fill($payload);
        $counterparty->save();

        return $counterparty->refresh()->load('linkedOrganization');
    }

    public function delete(int $counterpartyId, int $organizationId): void
    {
        $counterparty = $this->getById($counterpartyId, $organizationId);

        if (!$counterparty) {
            throw new BusinessLogicException(trans_message('counterparty.not_found'), Response::HTTP_NOT_FOUND);
        }

        if ($counterparty->contractParties()->exists() || $counterparty->projectsAsCustomer()->exists()) {
            throw new BusinessLogicException(trans_message('counterparty.in_use'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $counterparty->delete();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = $filters['q'] ?? $filters['search'] ?? $filters['name'] ?? null;

        if (is_string($search) && trim($search) !== '') {
            $value = '%' . trim($search) . '%';

            $query->where(function (Builder $searchQuery) use ($value): void {
                $searchQuery
                    ->where('name', 'like', $value)
                    ->orWhere('legal_name', 'like', $value)
                    ->orWhere('inn', 'like', $value)
                    ->orWhere('kpp', 'like', $value);
            });
        }

        if (!empty($filters['inn']) && is_string($filters['inn'])) {
            $query->where('inn', $filters['inn']);
        }

        if (!empty($filters['role']) && is_string($filters['role'])) {
            $query->whereJsonContains('roles', $filters['role']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    private function ensureInnKppIsUnique(
        int $organizationId,
        ?string $inn,
        ?string $kpp,
        ?int $ignoredCounterpartyId = null
    ): void {
        if ($inn === null || trim($inn) === '') {
            return;
        }

        $query = Counterparty::query()
            ->where('organization_id', $organizationId)
            ->where('inn', $inn)
            ->where(function (Builder $kppQuery) use ($kpp): void {
                if ($kpp === null || trim($kpp) === '') {
                    $kppQuery->whereNull('kpp')->orWhere('kpp', '');

                    return;
                }

                $kppQuery->where('kpp', $kpp);
            });

        if ($ignoredCounterpartyId !== null) {
            $query->whereKeyNot($ignoredCounterpartyId);
        }

        if ($query->exists()) {
            throw new BusinessLogicException(trans_message('counterparty.duplicate_inn_kpp'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function normalizeSortField(string $sortField): string
    {
        return in_array($sortField, self::SORTABLE_FIELDS, true) ? $sortField : self::DEFAULT_SORT_FIELD;
    }

    private function normalizeSortDirection(string $sortDirection): string
    {
        $sortDirection = strtolower($sortDirection);

        return in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : self::DEFAULT_SORT_DIRECTION;
    }
}
