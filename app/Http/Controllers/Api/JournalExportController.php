<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalExport;
use App\Services\ConstructionJournal\JournalExportWorkflowService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class JournalExportController extends Controller
{
    public function __construct(private readonly JournalExportWorkflowService $workflow) {}

    public function exportKS6(Request $request, ConstructionJournal $journal): JsonResponse
    {
        return $this->queue($request, $journal, 'ks6', [
            'format' => 'required|in:xlsx,pdf', 'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from', 'estimate_id' => 'nullable|integer',
        ]);
    }

    public function exportExtended(Request $request, ConstructionJournal $journal): JsonResponse
    {
        return $this->queue($request, $journal, 'extended', [
            'format' => 'sometimes|in:xlsx,pdf', 'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from', 'include_materials' => 'sometimes|boolean',
            'include_equipment' => 'sometimes|boolean', 'include_workers' => 'sometimes|boolean',
            'estimate_id' => 'nullable|integer',
        ]);
    }

    public function exportDailyReport(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        return $this->queue($request, $entry->journal, 'daily', [], $entry);
    }

    public function status(Request $request, JournalExport $export): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return AdminResponse::error(trans_message('errors.unauthorized'), 401);
            }

            return AdminResponse::success($this->workflow->payload($export, $user));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        }
    }

    private function queue(Request $request, ConstructionJournal $journal, string $type, array $rules,
        ?ConstructionJournalEntry $entry = null): JsonResponse
    {
        try {
            $this->authorize('export', $journal);
            $validated = $request->validate($rules);
            $key = trim((string) $request->header('Idempotency-Key'));
            if ($key === '' || mb_strlen($key) > 128) {
                throw ValidationException::withMessages(['idempotency_key' => [
                    trans_message('construction_journal.errors.idempotency_key_required'),
                ]]);
            }
            if ($type === 'extended') {
                $validated = array_merge([
                    'date_from' => $journal->start_date->toDateString(),
                    'date_to' => ($journal->end_date ?? now())->toDateString(),
                    'include_materials' => true, 'include_equipment' => true, 'include_workers' => true,
                ], $validated);
            }
            $export = $this->workflow->request($request->user(), $journal, $type,
                $validated['format'] ?? 'pdf', $validated, $key, $entry);

            return AdminResponse::success($this->workflow->payload($export, $request->user()),
                trans_message('construction_journal.messages.export_queued'), 202);
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('construction_journal.errors.validation_failed'), 422,
                $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.export_request_failed', [
                'user_id' => $request->user()?->id, 'journal_id' => $journal->id,
                'type' => $type, 'exception_class' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.export_failed'), 500);
        }
    }
}
