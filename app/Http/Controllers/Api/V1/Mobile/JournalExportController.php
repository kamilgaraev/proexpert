<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Services\Mobile\MobileConstructionJournalService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JournalExportController extends Controller
{
    public function __construct(
        private readonly OfficialFormsExportService $exportService,
        private readonly MobileConstructionJournalService $mobileJournalService
    ) {
    }

    public function exportKS6(ConstructionJournal $journal, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('export', $journal);

            $validated = $request->validate([
                'format' => 'required|in:xlsx,pdf',
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);

            $from = Carbon::parse($validated['date_from']);
            $to = Carbon::parse($validated['date_to']);

            $path = $validated['format'] === 'pdf'
                ? $this->exportService->exportKS6ToPdf($journal, $from, $to)
                : $this->exportService->exportKS6ToExcel($journal, $from, $to);

            return MobileResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_export.ks6.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.export_failed'), 500);
        }
    }

    public function exportExtended(ConstructionJournal $journal, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('export', $journal);

            $validated = $request->validate([
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
                'include_materials' => 'required|boolean',
                'include_equipment' => 'required|boolean',
                'include_workers' => 'required|boolean',
            ]);

            $options = [
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'include_materials' => $validated['include_materials'],
                'include_equipment' => $validated['include_equipment'],
                'include_workers' => $validated['include_workers'],
            ];

            $path = $this->exportService->exportExtendedReportToExcel($journal, $options);

            return MobileResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_export.extended.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.export_failed'), 500);
        }
    }

    public function exportDailyReport(ConstructionJournalEntry $entry, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('export', $entry->journal);

            $path = $this->exportService->exportDailyReportToPdf($entry);

            return MobileResponse::success(
                $this->buildExportPayload($path),
                trans_message('construction_journal.messages.export_ready')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_export.daily.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.export_failed'), 500);
        }
    }

    private function buildExportPayload(string $path): array
    {
        $expiresAt = now()->addMinutes(15);

        return [
            'url' => $this->exportService->getFileService()->temporaryUrl($path, 15),
            'filename' => basename($path),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
