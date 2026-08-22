<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalExport;
use App\Services\ConstructionJournal\JournalExportWorkflowService;
use App\Services\Mobile\MobileConstructionJournalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class JournalExportController extends Controller
{
    public function __construct(private readonly JournalExportWorkflowService $workflow,
        private readonly MobileConstructionJournalService $journals) {}

    public function exportKS6(ConstructionJournal $journal, Request $request): JsonResponse
    {
        return $this->queue($request, $journal, 'ks6', [
            'format' => 'required|in:xlsx,pdf', 'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from', 'estimate_id' => 'nullable|integer',
        ]);
    }

    public function exportExtended(ConstructionJournal $journal, Request $request): JsonResponse
    {
        return $this->queue($request, $journal, 'extended', [
            'format' => 'sometimes|in:xlsx,pdf', 'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from', 'include_materials' => 'required|boolean',
            'include_equipment' => 'required|boolean', 'include_workers' => 'required|boolean',
            'estimate_id' => 'nullable|integer',
        ]);
    }

    public function exportDailyReport(ConstructionJournalEntry $entry, Request $request): JsonResponse
    {
        return $this->queue($request, $entry->journal, 'daily', [], $entry);
    }

    public function status(JournalExport $export, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->workflow->payload($export, $user));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        }
    }

    private function queue(Request $request, ConstructionJournal $journal, string $type, array $rules,
        ?ConstructionJournalEntry $entry = null): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }
            $this->journals->assertJournalAccess($user, $journal);
            $this->authorize('export', $journal);
            $validated = $request->validate($rules);
            $key = trim((string) $request->header('Idempotency-Key'));
            if ($key === '' || mb_strlen($key) > 128) {
                throw ValidationException::withMessages(['idempotency_key' => [
                    trans_message('construction_journal.errors.idempotency_key_required'),
                ]]);
            }
            $export = $this->workflow->request($user, $journal, $type, $validated['format'] ?? 'pdf',
                $validated, $key, $entry);

            return MobileResponse::success($this->workflow->payload($export, $user),
                trans_message('construction_journal.messages.export_queued'), 202);
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('construction_journal.errors.validation_failed'), 422,
                $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.export_request_failed', [
                'user_id' => $request->user()?->id, 'journal_id' => $journal->id,
                'type' => $type, 'exception_class' => $exception::class,
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.export_failed'), 500);
        }
    }
}
