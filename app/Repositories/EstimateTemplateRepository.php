<?php

namespace App\Repositories;

use App\Models\EstimateTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class EstimateTemplateRepository
{
    protected int $cacheTtl = 3600;

    public function find(int $id): ?EstimateTemplate
    {
        return EstimateTemplate::with(['organization', 'createdBy'])->find($id);
    }

    public function findOrFail(int $id): EstimateTemplate
    {
        return EstimateTemplate::with(['organization', 'createdBy'])->findOrFail($id);
    }

    public function create(array $data): EstimateTemplate
    {
        $template = EstimateTemplate::create($data);
        $this->clearCache($template->organization_id);
        return $template;
    }

    public function update(EstimateTemplate $template, array $data): bool
    {
        $result = $template->update($data);
        $this->clearCache($template->organization_id);
        return $result;
    }

    public function delete(EstimateTemplate $template): bool
    {
        $organizationId = $template->organization_id;
        $result = $template->delete();
        $this->clearCache($organizationId);
        return $result;
    }

    public function getByOrganization(int $organizationId, bool $includePublic = true): Collection
    {
        $cacheKey = "estimate_templates:org:{$organizationId}:public:" . ($includePublic ? '1' : '0');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($organizationId, $includePublic) {
            $query = EstimateTemplate::with(['createdBy'])
                ->where('organization_id', $organizationId);

            if ($includePublic) {
                $query->orWhere('is_public', true);
            }

            return $query->orderBy('usage_count', 'desc')->get();
        });
    }

    public function getPublicTemplates(): Collection
    {
        return Cache::remember('estimate_templates:public', $this->cacheTtl, function () {
            return EstimateTemplate::with(['organization', 'createdBy'])
                ->where('is_public', true)
                ->orderBy('usage_count', 'desc')
                ->get();
        });
    }

    public function getByCategory(int $organizationId, string $category): Collection
    {
        return EstimateTemplate::with(['createdBy'])
            ->where(function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                    ->orWhere('is_public', true);
            })
            ->where('work_type_category', $category)
            ->orderBy('usage_count', 'desc')
            ->get();
    }

    public function incrementUsage(EstimateTemplate $template): void
    {
        $template->incrementUsage();
        $this->clearCache($template->organization_id);
    }

    protected function clearCache(int $organizationId): void
    {
        Cache::forget("estimate_templates:org:{$organizationId}:public:1");
        Cache::forget("estimate_templates:org:{$organizationId}:public:0");
        Cache::forget('estimate_templates:public');
    }
}

