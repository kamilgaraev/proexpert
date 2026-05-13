<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\LaborResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class LaborResourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $query = LaborResource::where('organization_id', $organizationId);

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }

            if ($request->filled('profession')) {
                $query->where('profession', $request->input('profession'));
            }

            if ($request->filled('skill_level')) {
                $query->where('skill_level', $request->input('skill_level'));
            }

            if ($request->filled('search')) {
                $search = mb_strtolower((string) $request->input('search'));
                $query->where(function ($builder) use ($search): void {
                    $builder->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(profession) LIKE ?', ["%{$search}%"]);
                });
            }

            $sortBy = $this->normalizeSortBy((string) $request->input('sort_by', 'name'));
            $sortOrder = $this->normalizeSortDirection((string) $request->input('sort_direction', $request->input('sort_order', 'asc')));

            $query->orderBy($sortBy, $sortOrder);

            $perPage = $this->normalizePerPage($request->input('per_page', 15));
            $resources = $query->with('measurementUnit')->paginate($perPage);

            return AdminResponse::paginated(
                $resources->items(),
                [
                    'current_page' => $resources->currentPage(),
                    'per_page' => $resources->perPage(),
                    'total' => $resources->total(),
                    'last_page' => $resources->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('labor_resources.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.list_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $resource = LaborResource::where('organization_id', $organizationId)
                ->with('measurementUnit')
                ->findOrFail($id);

            return AdminResponse::success($resource);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.labor_resources.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('labor_resources.show.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'labor_resource_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.show_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $validated = $request->validate([
                'code' => 'nullable|string|max:100',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'profession' => 'nullable|string',
                'skill_level' => 'nullable|integer|min:1|max:8',
                'measurement_unit_id' => [
                    'nullable',
                    Rule::exists('measurement_units', 'id')->where('organization_id', $organizationId),
                ],
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'monthly_rate' => 'nullable|numeric',
                'coefficient' => 'nullable|numeric',
                'work_hours_per_shift' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
            ]);

            $exists = !empty($validated['code']) && LaborResource::where('organization_id', $organizationId)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('budget_estimates.labor_resources.code_exists'), 422);
            }

            $resource = LaborResource::create(array_merge($validated, [
                'organization_id' => $organizationId,
            ]));

            return AdminResponse::success(
                $resource->load('measurementUnit'),
                trans_message('budget_estimates.labor_resources.created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('budget_estimates.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('labor_resources.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.create_error'), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $resource = LaborResource::where('organization_id', $organizationId)->findOrFail($id);

            $validated = $request->validate([
                'code' => 'sometimes|nullable|string|max:100',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'profession' => 'nullable|string',
                'skill_level' => 'nullable|integer|min:1|max:8',
                'measurement_unit_id' => [
                    'nullable',
                    Rule::exists('measurement_units', 'id')->where('organization_id', $organizationId),
                ],
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'monthly_rate' => 'nullable|numeric',
                'coefficient' => 'nullable|numeric',
                'work_hours_per_shift' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
            ]);

            if (!empty($validated['code']) && $validated['code'] !== $resource->code) {
                $exists = LaborResource::where('organization_id', $organizationId)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('budget_estimates.labor_resources.code_exists'), 422);
                }
            }

            $resource->update($validated);

            return AdminResponse::success(
                $resource->load('measurementUnit'),
                trans_message('budget_estimates.labor_resources.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.labor_resources.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('budget_estimates.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('labor_resources.update.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'labor_resource_id' => $id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $resource = LaborResource::where('organization_id', $organizationId)->findOrFail($id);
            $usedInEstimates = $resource->estimateItems()->count();

            if ($usedInEstimates > 0) {
                return AdminResponse::error(
                    trans_message('budget_estimates.labor_resources.used_in_estimates', ['count' => $usedInEstimates]),
                    422
                );
            }

            $resource->delete();

            return AdminResponse::success(null, trans_message('budget_estimates.labor_resources.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.labor_resources.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('labor_resources.destroy.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'labor_resource_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.delete_error'), 500);
        }
    }

    public function professions(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $professions = LaborResource::where('organization_id', $organizationId)
                ->whereNotNull('profession')
                ->selectRaw('profession as name, COUNT(*) as count')
                ->groupBy('profession')
                ->orderBy('profession')
                ->get();

            return AdminResponse::success($professions);
        } catch (\Exception $e) {
            Log::error('labor_resources.professions.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.professions_error'), 500);
        }
    }

    public function categories(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $categories = LaborResource::where('organization_id', $organizationId)
                ->whereNotNull('category')
                ->selectRaw('category as name, COUNT(*) as count')
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            return AdminResponse::success($categories);
        } catch (\Exception $e) {
            Log::error('labor_resources.categories.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.categories_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $total = LaborResource::where('organization_id', $organizationId)->count();
            $active = LaborResource::where('organization_id', $organizationId)->active()->count();
            $byCategory = LaborResource::where('organization_id', $organizationId)
                ->selectRaw('category, COUNT(*) as count')
                ->whereNotNull('category')
                ->groupBy('category')
                ->pluck('count', 'category');
            $bySkillLevel = LaborResource::where('organization_id', $organizationId)
                ->selectRaw('skill_level, COUNT(*) as count')
                ->whereNotNull('skill_level')
                ->groupBy('skill_level')
                ->pluck('count', 'skill_level');
            $professions = LaborResource::where('organization_id', $organizationId)
                ->whereNotNull('profession')
                ->selectRaw('profession as name, COUNT(*) as count')
                ->groupBy('profession')
                ->orderBy('profession')
                ->get();
            $categories = LaborResource::where('organization_id', $organizationId)
                ->whereNotNull('category')
                ->selectRaw('category as name, COUNT(*) as count')
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            return AdminResponse::success([
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'professions' => $professions,
                'total_professions' => $professions->count(),
                'categories' => $categories,
                'total_categories' => $categories->count(),
                'by_category' => $byCategory,
                'by_skill_level' => $bySkillLevel,
                'average_hourly_rate' => LaborResource::where('organization_id', $organizationId)->avg('hourly_rate'),
                'average_shift_rate' => LaborResource::where('organization_id', $organizationId)->avg('shift_rate'),
            ]);
        } catch (\Exception $e) {
            Log::error('labor_resources.statistics.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.statistics_error'), 500);
        }
    }

    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $query = mb_strtolower(trim((string) $request->query('q', '')));
            $limit = min(max((int) $request->query('limit', 20), 1), 50);

            $resources = LaborResource::where('organization_id', $organizationId)
                ->when($query !== '', function ($builder) use ($query): void {
                    $builder->where(function ($nested) use ($query): void {
                        $nested->whereRaw('LOWER(name) LIKE ?', ["%{$query}%"])
                            ->orWhereRaw('LOWER(code) LIKE ?', ["%{$query}%"])
                            ->orWhereRaw('LOWER(profession) LIKE ?', ["%{$query}%"]);
                    });
                })
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'code', 'name', 'profession', 'skill_level', 'hourly_rate']);

            return AdminResponse::success($resources);
        } catch (\Exception $e) {
            Log::error('labor_resources.autocomplete.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.labor_resources.list_error'), 500);
        }
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $value = (int) $perPage;

        if ($value <= 0) {
            return 1000;
        }

        return min($value, 1000);
    }

    private function normalizeSortBy(string $sortBy): string
    {
        return in_array($sortBy, ['name', 'code', 'profession', 'category', 'skill_level', 'hourly_rate', 'created_at'], true)
            ? $sortBy
            : 'name';
    }

    private function normalizeSortDirection(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
