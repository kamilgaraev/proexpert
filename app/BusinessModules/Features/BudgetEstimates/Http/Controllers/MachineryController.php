<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Machinery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

use function trans_message;

class MachineryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $query = Machinery::where('organization_id', $organizationId);

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }

            if ($request->filled('search')) {
                $search = mb_strtolower((string) $request->input('search'));
                $query->where(function ($builder) use ($search): void {
                    $builder->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(model) LIKE ?', ["%{$search}%"]);
                });
            }

            $sortBy = $this->normalizeSortBy((string) $request->input('sort_by', 'name'));
            $sortOrder = $this->normalizeSortDirection((string) $request->input('sort_direction', $request->input('sort_order', 'asc')));
            $perPage = $this->normalizePerPage($request->input('per_page', 15));

            $machinery = $query
                ->orderBy($sortBy, $sortOrder)
                ->with('measurementUnit')
                ->paginate($perPage);

            return AdminResponse::paginated(
                $machinery->items(),
                [
                    'current_page' => $machinery->currentPage(),
                    'per_page' => $machinery->perPage(),
                    'total' => $machinery->total(),
                    'last_page' => $machinery->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('machinery.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.list_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $machinery = Machinery::where('organization_id', $organizationId)
                ->with('measurementUnit')
                ->findOrFail($id);

            return AdminResponse::success($machinery);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.machinery.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('machinery.show.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'machinery_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.show_error'), 500);
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
                'type' => 'nullable|string',
                'measurement_unit_id' => [
                    'nullable',
                    Rule::exists('measurement_units', 'id')->where('organization_id', $organizationId),
                ],
                'model' => 'nullable|string',
                'manufacturer' => 'nullable|string',
                'power' => 'nullable|numeric',
                'capacity' => 'nullable|numeric',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'fuel_consumption' => 'nullable|numeric',
                'fuel_type' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            $exists = !empty($validated['code']) && Machinery::where('organization_id', $organizationId)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('budget_estimates.machinery.code_exists'), 422);
            }

            $machinery = Machinery::create(array_merge($validated, [
                'organization_id' => $organizationId,
            ]));

            return AdminResponse::success(
                $machinery->load('measurementUnit'),
                trans_message('budget_estimates.machinery.created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('budget_estimates.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('machinery.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.create_error'), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $machinery = Machinery::where('organization_id', $organizationId)->findOrFail($id);

            $validated = $request->validate([
                'code' => 'sometimes|nullable|string|max:100',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'type' => 'nullable|string',
                'measurement_unit_id' => [
                    'nullable',
                    Rule::exists('measurement_units', 'id')->where('organization_id', $organizationId),
                ],
                'model' => 'nullable|string',
                'manufacturer' => 'nullable|string',
                'power' => 'nullable|numeric',
                'capacity' => 'nullable|numeric',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'fuel_consumption' => 'nullable|numeric',
                'fuel_type' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            if (!empty($validated['code']) && $validated['code'] !== $machinery->code) {
                $exists = Machinery::where('organization_id', $organizationId)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('budget_estimates.machinery.code_exists'), 422);
                }
            }

            $machinery->update($validated);

            return AdminResponse::success(
                $machinery->load('measurementUnit'),
                trans_message('budget_estimates.machinery.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.machinery.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('budget_estimates.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('machinery.update.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'machinery_id' => $id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $machinery = Machinery::where('organization_id', $organizationId)->findOrFail($id);
            $usedInEstimates = $machinery->estimateItems()->count();

            if ($usedInEstimates > 0) {
                return AdminResponse::error(
                    trans_message('budget_estimates.machinery.used_in_estimates', ['count' => $usedInEstimates]),
                    422
                );
            }

            $machinery->delete();

            return AdminResponse::success(null, trans_message('budget_estimates.machinery.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('budget_estimates.machinery.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('machinery.destroy.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'machinery_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.delete_error'), 500);
        }
    }

    public function categories(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $categories = Machinery::where('organization_id', $organizationId)
                ->whereNotNull('category')
                ->selectRaw('category as name, COUNT(*) as count')
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            return AdminResponse::success($categories);
        } catch (\Exception $e) {
            Log::error('machinery.categories.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.categories_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $total = Machinery::where('organization_id', $organizationId)->count();
            $active = Machinery::where('organization_id', $organizationId)->active()->count();
            $byCategory = Machinery::where('organization_id', $organizationId)
                ->selectRaw('category, COUNT(*) as count')
                ->whereNotNull('category')
                ->groupBy('category')
                ->pluck('count', 'category');
            $categories = Machinery::where('organization_id', $organizationId)
                ->whereNotNull('category')
                ->selectRaw('category as name, COUNT(*) as count')
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            return AdminResponse::success([
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'categories' => $categories,
                'total_categories' => $categories->count(),
                'by_category' => $byCategory,
                'average_hourly_rate' => Machinery::where('organization_id', $organizationId)->avg('hourly_rate'),
            ]);
        } catch (\Exception $e) {
            Log::error('machinery.statistics.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.statistics_error'), 500);
        }
    }

    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $query = mb_strtolower(trim((string) $request->query('q', '')));
            $limit = min(max((int) $request->query('limit', 20), 1), 50);

            $machinery = Machinery::where('organization_id', $organizationId)
                ->when($query !== '', function ($builder) use ($query): void {
                    $builder->where(function ($nested) use ($query): void {
                        $nested->whereRaw('LOWER(name) LIKE ?', ["%{$query}%"])
                            ->orWhereRaw('LOWER(code) LIKE ?', ["%{$query}%"])
                            ->orWhereRaw('LOWER(model) LIKE ?', ["%{$query}%"]);
                    });
                })
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'code', 'name', 'category', 'hourly_rate']);

            return AdminResponse::success($machinery);
        } catch (\Exception $e) {
            Log::error('machinery.autocomplete.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.machinery.list_error'), 500);
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
        return in_array($sortBy, ['name', 'code', 'category', 'model', 'manufacturer', 'hourly_rate', 'created_at'], true)
            ? $sortBy
            : 'name';
    }

    private function normalizeSortDirection(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
