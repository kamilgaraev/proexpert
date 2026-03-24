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

use function trans_message;

class MachineryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $query = Machinery::where('organization_id', $organizationId);

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($builder) use ($search): void {
                    $builder->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('code', 'ILIKE', "%{$search}%")
                        ->orWhere('model', 'ILIKE', "%{$search}%");
                });
            }

            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $perPage = min((int) $request->input('per_page', 15), 100);

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
                'code' => 'required|string|max:100',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'type' => 'nullable|string',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
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

            $exists = Machinery::where('organization_id', $organizationId)
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
                'code' => 'sometimes|string|max:100',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'type' => 'nullable|string',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
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

            if (isset($validated['code']) && $validated['code'] !== $machinery->code) {
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
                ->distinct('category')
                ->pluck('category');

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

            return AdminResponse::success([
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'by_category' => $byCategory,
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
}
