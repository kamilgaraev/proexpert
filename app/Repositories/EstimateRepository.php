<?php

namespace App\Repositories;

use App\Models\Estimate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EstimateRepository
{
    public function find(int $id): ?Estimate
    {
        return Estimate::with(['organization', 'project', 'contract', 'sections', 'items', 'approvedBy'])
            ->find($id);
    }

    public function findOrFail(int $id): Estimate
    {
        return Estimate::with(['organization', 'project', 'contract', 'sections', 'items', 'approvedBy'])
            ->findOrFail($id);
    }

    public function create(array $data): Estimate
    {
        return Estimate::create($data);
    }

    public function update(Estimate $estimate, array $data): bool
    {
        return $estimate->update($data);
    }

    public function delete(Estimate $estimate): bool
    {
        return $estimate->delete();
    }

    public function getByOrganization(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Estimate::with(['project', 'contract', 'approvedBy'])
            ->where('organization_id', $organizationId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (isset($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function getByProject(int $projectId): Collection
    {
        return Estimate::with(['sections', 'items'])
            ->where('project_id', $projectId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByContract(int $contractId): Collection
    {
        return Estimate::with(['sections', 'items'])
            ->where('contract_id', $contractId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getVersions(Estimate $estimate): Collection
    {
        return Estimate::where('parent_estimate_id', $estimate->id)
            ->orderBy('version', 'desc')
            ->get();
    }

    public function getLatestVersion(Estimate $parentEstimate): ?Estimate
    {
        return Estimate::where('parent_estimate_id', $parentEstimate->id)
            ->orderBy('version', 'desc')
            ->first();
    }

    public function duplicate(Estimate $estimate, array $overrides = []): Estimate
    {
        $data = $estimate->toArray();
        
        unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);
        
        $data = array_merge($data, $overrides);
        
        return $this->create($data);
    }

    public function existsByNumber(int $organizationId, string $number, ?int $excludeId = null): bool
    {
        $query = Estimate::where('organization_id', $organizationId)
            ->where('number', $number);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}

