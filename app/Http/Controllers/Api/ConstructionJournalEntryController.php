<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConstructionJournalEntryController extends Controller
{
    public function __construct(
        protected ConstructionJournalService $journalService,
        protected JournalApprovalService $approvalService,
        protected ConstructionJournalPayloadService $payloadService
    ) {
    }

    public function store(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('create', [ConstructionJournalEntry::class, $journal]);

            $validated = $request->validate($this->entryRules());
            $entry = $this->journalService->createEntry($journal, $validated, $request->user());

            return AdminResponse::success(
                $this->payloadService->mapEntry($entry, $request->user()),
                trans_message('construction_journal.messages.entry_created'),
                201
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.store.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.entry_create_failed'), 500);
        }
    }

    public function show(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('view', $entry);

            $entry->load([
                'journal.project',
                'journal.contract',
                'journal.createdBy',
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'workVolumes.estimateItem',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'workers',
                'equipment',
                'materials.material',
            ]);

            return AdminResponse::success($this->payloadService->mapEntry($entry, $request->user()));
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.load_failed'), 500);
        }
    }

    public function update(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('update', $entry);

            if (!$entry->canBeEdited()) {
                return AdminResponse::error(trans_message('construction_journal.errors.entry_edit_forbidden_status'), 422);
            }

            $validated = $request->validate($this->entryRules(true));
            $entry = $this->journalService->updateEntry($entry, $validated);

            return AdminResponse::success(
                $this->payloadService->mapEntry($entry, $request->user()),
                trans_message('construction_journal.messages.entry_updated')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.update.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.entry_update_failed'), 500);
        }
    }

    public function destroy(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('delete', $entry);

            if (!$entry->canBeEdited()) {
                return AdminResponse::error(trans_message('construction_journal.errors.entry_delete_forbidden_status'), 422);
            }

            $this->journalService->deleteEntry($entry);

            return AdminResponse::success(null, trans_message('construction_journal.messages.entry_deleted'));
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.destroy.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.entry_delete_failed'), 500);
        }
    }

    public function submit(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('update', $entry);

            $entry = $this->approvalService->submitForApproval($entry->load(['journal', 'createdBy', 'workVolumes']));

            return AdminResponse::success(
                $this->payloadService->mapEntry($entry->load(['journal', 'createdBy', 'approvedBy', 'workVolumes']), $request->user()),
                trans_message('construction_journal.messages.entry_submitted')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.submit.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.submit_failed'), 500);
        }
    }

    public function approve(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('approve', $entry);

            $entry = $this->approvalService->approve($entry->load(['journal', 'createdBy', 'scheduleTask', 'workVolumes']), $request->user());

            return AdminResponse::success(
                $this->payloadService->mapEntry($entry->load([
                    'journal',
                    'scheduleTask',
                    'estimate',
                    'createdBy',
                    'approvedBy',
                    'workVolumes.estimateItem',
                    'workVolumes.workType',
                    'workVolumes.measurementUnit',
                    'workers',
                    'equipment',
                    'materials.material',
                ]), $request->user()),
                trans_message('construction_journal.messages.entry_approved')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.approve.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.approve_failed'), 500);
        }
    }

    public function reject(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $this->authorize('approve', $entry);

            $validated = $request->validate([
                'reason' => 'required|string|min:10',
            ]);

            $entry = $this->approvalService->reject($entry->load(['journal', 'createdBy']), $request->user(), $validated['reason']);

            return AdminResponse::success(
                $this->payloadService->mapEntry($entry->load([
                    'journal',
                    'scheduleTask',
                    'estimate',
                    'createdBy',
                    'approvedBy',
                    'workVolumes.estimateItem',
                    'workVolumes.workType',
                    'workVolumes.measurementUnit',
                    'workers',
                    'equipment',
                    'materials.material',
                ]), $request->user()),
                trans_message('construction_journal.messages.entry_rejected')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal_entry.reject.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.reject_failed'), 500);
        }
    }

    private function entryRules(bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        return [
            'schedule_task_id' => $prefix . 'nullable|integer',
            'estimate_id' => $prefix . 'nullable|integer',
            'entry_date' => $partial ? 'sometimes|date' : 'required|date',
            'entry_number' => $prefix . 'nullable|integer|min:1',
            'work_description' => $partial ? 'sometimes|string' : 'required|string',
            'weather_conditions' => $prefix . 'nullable|array',
            'weather_conditions.temperature' => 'nullable|numeric',
            'weather_conditions.precipitation' => 'nullable|string',
            'weather_conditions.wind_speed' => 'nullable|numeric',
            'problems_description' => $prefix . 'nullable|string',
            'safety_notes' => $prefix . 'nullable|string',
            'visitors_notes' => $prefix . 'nullable|string',
            'quality_notes' => $prefix . 'nullable|string',
            'work_volumes' => $prefix . 'nullable|array',
            'work_volumes.*.estimate_item_id' => 'nullable|integer',
            'work_volumes.*.work_type_id' => 'nullable|integer',
            'work_volumes.*.quantity' => 'required|numeric|min:0',
            'work_volumes.*.measurement_unit_id' => 'nullable|integer',
            'work_volumes.*.notes' => 'nullable|string',
            'workers' => $prefix . 'nullable|array',
            'workers.*.specialty' => 'required|string',
            'workers.*.workers_count' => 'required|integer|min:1',
            'workers.*.hours_worked' => 'nullable|numeric|min:0',
            'equipment' => $prefix . 'nullable|array',
            'equipment.*.equipment_name' => 'required|string',
            'equipment.*.equipment_type' => 'nullable|string',
            'equipment.*.quantity' => 'nullable|integer|min:1',
            'equipment.*.hours_used' => 'nullable|numeric|min:0',
            'materials' => $prefix . 'nullable|array',
            'materials.*.material_id' => 'nullable|integer',
            'materials.*.material_name' => 'required|string',
            'materials.*.quantity' => 'required|numeric|min:0',
            'materials.*.measurement_unit' => 'required|string',
            'materials.*.notes' => 'nullable|string',
        ];
    }
}
