<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalEntryWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\ApproveJournalEntryRequest;
use App\Http\Requests\ConstructionJournal\RejectJournalEntryRequest;
use App\Http\Requests\ConstructionJournal\StoreJournalEntryRequest;
use App\Http\Requests\ConstructionJournal\UpdateJournalEntryRequest;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Services\Mobile\MobileConstructionJournalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConstructionJournalEntryController extends Controller
{
    public function __construct(
        private readonly MobileConstructionJournalService $mobileJournalService,
        private readonly ConstructionJournalService $journalService,
        private readonly JournalApprovalService $approvalService,
        private readonly JournalEntryWorkflowService $entryWorkflowService
    ) {}

    public function store(ConstructionJournal $journal, StoreJournalEntryRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('create', [ConstructionJournalEntry::class, $journal]);

            $validated = $request->validated();
            $entry = $this->entryWorkflowService->create($journal, $validated, $user);

            return MobileResponse::success(
                $this->mobileJournalService->mapMobileEntry($entry, $user),
                trans_message('construction_journal.messages.entry_created'),
                201
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.store.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.entry_create_failed'), 500);
        }
    }

    public function show(ConstructionJournalEntry $entry, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
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

            return MobileResponse::success($this->mobileJournalService->mapMobileEntry($entry, $user));
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.load_failed'), 500);
        }
    }

    public function update(ConstructionJournalEntry $entry, UpdateJournalEntryRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('update', $entry);

            if (! $entry->canBeEdited()) {
                return MobileResponse::error(trans_message('construction_journal.errors.entry_edit_forbidden_status'), 422);
            }

            $validated = $request->validated();
            $entry = $this->journalService->updateEntry($entry, $validated);

            return MobileResponse::success(
                $this->mobileJournalService->mapMobileEntry($entry, $user),
                trans_message('construction_journal.messages.entry_updated')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.update.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.entry_update_failed'), 500);
        }
    }

    public function destroy(ConstructionJournalEntry $entry, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('delete', $entry);

            if (! $entry->canBeEdited()) {
                return MobileResponse::error(trans_message('construction_journal.errors.entry_delete_forbidden_status'), 422);
            }

            $this->journalService->deleteEntry($entry);

            return MobileResponse::success(null, trans_message('construction_journal.messages.entry_deleted'));
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.destroy.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.entry_delete_failed'), 500);
        }
    }

    public function submit(ConstructionJournalEntry $entry, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('update', $entry);

            $entry = $this->approvalService->submitForApproval(
                $entry->load(['journal', 'createdBy', 'workVolumes']),
                $user
            );

            return MobileResponse::success(
                $this->mobileJournalService->mapMobileEntry($entry->load([
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
                ]), $user),
                trans_message('construction_journal.messages.entry_submitted')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.submit.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.submit_failed'), 500);
        }
    }

    public function approve(ConstructionJournalEntry $entry, ApproveJournalEntryRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('approve', $entry);

            $validated = $request->validated();
            $entry = $this->approvalService->approve(
                $entry->load(['journal', 'createdBy', 'scheduleTask', 'workVolumes']),
                $user,
                $validated['override'] ?? null
            );

            return MobileResponse::success(
                $this->mobileJournalService->mapMobileEntry($entry->load([
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
                ]), $user),
                trans_message('construction_journal.messages.entry_approved')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('project.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.approve.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.approve_failed'), 500);
        }
    }

    public function reject(ConstructionJournalEntry $entry, RejectJournalEntryRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $entry->journal);
            $this->authorize('approve', $entry);

            $validated = $request->validated();

            $entry = $this->approvalService->reject($entry->load(['journal', 'createdBy']), $user, $validated['reason']);

            return MobileResponse::success(
                $this->mobileJournalService->mapMobileEntry($entry->load([
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
                ]), $user),
                trans_message('construction_journal.messages.entry_rejected')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal_entry.reject.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'entry_id' => $entry->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.reject_failed'), 500);
        }
    }
}
