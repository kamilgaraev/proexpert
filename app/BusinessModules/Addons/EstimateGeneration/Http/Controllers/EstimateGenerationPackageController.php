<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Export\EstimateGenerationExporter;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function trans_message;

final class EstimateGenerationPackageController extends Controller
{
    public function __construct(
        private readonly EstimateGenerationExporter $exporter,
        private readonly EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard,
        private readonly EstimateGenerationPackagePresenter $presenter,
    ) {}

    public function index(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safeRead(function () use ($request, $project, $session): JsonResponse {
            $this->guard($request, $project, $session);

            $validated = $request->validate([
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'search' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);
            $query = $session->packages()->orderBy('sort_order')->orderBy('id');
            if (isset($validated['search'])) {
                $query->where('title', 'ilike', '%'.addcslashes((string) $validated['search'], '%_\\').'%');
            }
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            $perPage = (int) ($validated['per_page'] ?? 20);
            $summaryPackages = (clone $query)->get();
            $packages = $query->paginate($perPage);
            $payload = $this->presenter->collection($summaryPackages);
            $payload['packages'] = collect($packages->items())
                ->map(fn (EstimateGenerationPackage $package): array => $this->presenter->summary($package))
                ->values()
                ->all();
            $payload['meta'] = [
                'total' => $packages->total(),
                'current_page' => $packages->currentPage(),
                'per_page' => $packages->perPage(),
                'last_page' => max($packages->lastPage(), 1),
            ];

            return AdminResponse::success($payload);
        }, 'list packages', $project, $session);
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session, EstimateGenerationPackage $package): JsonResponse
    {
        return $this->safeRead(function () use ($request, $project, $session, $package): JsonResponse {
            $this->guard($request, $project, $session);
            if ((int) $package->session_id !== (int) $session->id) {
                abort(404);
            }
            $validated = $request->validate([
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'search' => ['nullable', 'string', 'max:255'],
                'pricing_status' => ['nullable', 'string', 'max:50'],
            ]);
            $query = $package->items()->whereNotIn('item_type', EstimateGenerationPackageItem::SERVICE_ITEM_TYPES)
                ->latestLogicalRevisions()
                ->orderBy('sort_order')
                ->orderBy('id');
            if (isset($validated['search'])) {
                $query->where('name', 'ilike', '%'.addcslashes((string) $validated['search'], '%_\\').'%');
            }
            if (isset($validated['pricing_status'])) {
                $query->where('metadata->pricing_status', $validated['pricing_status']);
            }
            $items = $query->paginate((int) ($validated['per_page'] ?? 25));

            $payload = $this->presenter->detail($package, collect($items->items()));
            $payload['meta'] = [
                ...$payload['meta'],
                'total' => $items->total(),
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'last_page' => max($items->lastPage(), 1),
            ];

            return AdminResponse::success($payload);
        }, 'show package', $project, $session);
    }

    public function draft(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safeRead(function () use ($request, $project, $session): JsonResponse {
            $this->guard($request, $project, $session);

            return AdminResponse::success($session->draft_payload ?? []);
        }, 'show draft', $project, $session);
    }

    public function export(Request $request, Project $project, EstimateGenerationSession $session): Response|StreamedResponse|JsonResponse
    {
        try {
            $this->guard($request, $project, $session);
            $draft = $session->draft_payload ?? [];
            $format = (string) $request->query('format', 'excel');
            if ($format === 'csv') {
                return response()->streamDownload(function () use ($draft): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['Локальная смета', 'Раздел', 'Работа', 'Ед.', 'Кол-во', 'Итого', 'Основание']);
                    foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
                        foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $section) {
                            foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $workItem) {
                                if (! is_array($workItem) || ! $this->finalWorkItemGuard->isFinalEstimateWorkItem($workItem)) {
                                    continue;
                                }
                                fputcsv($handle, [$localEstimate['title'] ?? '', $section['title'] ?? '', $workItem['name'] ?? '', $workItem['unit'] ?? '', $workItem['quantity'] ?? '', $workItem['total_cost'] ?? '', $workItem['quantity_basis'] ?? '']);
                            }
                        }
                    }
                    fclose($handle);
                }, 'estimate-generation-draft-'.$session->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            }
            if ($format === 'json') {
                return response()->streamDownload(static function () use ($draft): void {
                    echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }, 'estimate-generation-draft-'.$session->id.'.json', ['Content-Type' => 'application/json; charset=UTF-8']);
            }
            $result = $this->exporter->export($session->loadMissing(['project.organization', 'organization']));
            $filename = $result['filename'];

            return response($result['content'])
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"; filename*=UTF-8''".rawurlencode($filename));
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Export failed', ['failure_code' => 'export_failed', 'session_id' => $session->id]);

            return AdminResponse::error(trans_message('estimate_generation.export_error'), 500);
        }
    }

    private function guard(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        if ((int) $session->organization_id !== (int) $request->user()->current_organization_id || (int) $session->project_id !== (int) $project->id) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }

    /** @param callable(): JsonResponse $callback */
    private function safeRead(callable $callback, string $operation, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            return $callback();
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $exception->errors());
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Package read failed', ['operation' => $operation, 'project_id' => $project->id, 'session_id' => $session->id, 'failure_code' => 'package_read_failed']);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }
}
