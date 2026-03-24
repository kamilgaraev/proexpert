<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JournalExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService
    ) {
    }

    public function exportKS6(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('export', $journal);

            $validated = $request->validate([
                'format' => 'required|in:xlsx,pdf',
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);

            $from = Carbon::parse($validated['date_from']);
            $to = Carbon::parse($validated['date_to']);
            $format = $validated['format'];

            $path = $format === 'pdf'
                ? $this->exportService->exportKS6ToPdf($journal, $from, $to)
                : $this->exportService->exportKS6ToExcel($journal, $from, $to);

            return AdminResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_export.ks6.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.export_failed'), 500);
        }
    }

    public function exportDailyReport(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('export', $entry->journal);

            $path = $this->exportService->exportDailyReportToPdf($entry);

            return AdminResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_export.daily.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.export_failed'), 500);
        }
    }

    public function exportExtended(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('export', $journal);

            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'include_materials' => 'boolean',
                'include_equipment' => 'boolean',
                'include_workers' => 'boolean',
            ]);

            $options = [
                'date_from' => $validated['date_from'] ?? $journal->start_date,
                'date_to' => $validated['date_to'] ?? ($journal->end_date ?? now()),
                'include_materials' => $validated['include_materials'] ?? true,
                'include_equipment' => $validated['include_equipment'] ?? true,
                'include_workers' => $validated['include_workers'] ?? true,
            ];

            $path = $this->exportService->exportExtendedReportToExcel($journal, $options);

            return AdminResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_export.extended.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.export_failed'), 500);
        }
    }

    protected function buildExportPayload(string $path): array
    {
        $expiresAt = now()->addMinutes(15);

        return [
            'url' => $this->exportService->getFileService()->temporaryUrl($path, 15),
            'filename' => basename($path),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
