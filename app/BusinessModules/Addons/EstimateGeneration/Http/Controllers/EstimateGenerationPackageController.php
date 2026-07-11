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

            return AdminResponse::success($this->presenter->collection($session->packages()->get()));
        }, 'list packages', $project, $session);
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session, EstimateGenerationPackage $package): JsonResponse
    {
        return $this->safeRead(function () use ($request, $project, $session, $package): JsonResponse {
            $this->guard($request, $project, $session);
            if ((int) $package->session_id !== (int) $session->id) {
                abort(404);
            }
            $perPage = min(max((int) $request->query('per_page', 100), 1), 500);
            $items = $package->items()->whereNotIn('item_type', EstimateGenerationPackageItem::SERVICE_ITEM_TYPES)
                ->limit($perPage)->get();

            return AdminResponse::success($this->presenter->detail($package, $items));
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
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Package read failed', ['operation' => $operation, 'project_id' => $project->id, 'session_id' => $session->id, 'failure_code' => 'package_read_failed']);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }
}
