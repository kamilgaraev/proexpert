<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournal;
use App\Services\Mobile\MobileConstructionJournalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConstructionJournalController extends Controller
{
    public function __construct(
        private readonly MobileConstructionJournalService $mobileJournalService,
        private readonly ConstructionJournalService $journalService,
        private readonly ConstructionJournalPayloadService $payloadService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $project = $this->mobileJournalService->resolveProject($user, $request->integer('project_id'));

            return MobileResponse::success(
                $this->mobileJournalService->buildJournalList(
                    $user,
                    $project,
                    max(1, $request->integer('page', 1)),
                    max(1, $request->integer('per_page', 15)),
                    $request->string('status')->toString() ?: null
                )
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $request->input('project_id'),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.load_failed'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $project = $this->mobileJournalService->resolveProject($user, $request->integer('project_id'));
            $this->authorize('create', [ConstructionJournal::class, $project]);

            $validated = $request->validate([
                'project_id' => 'required|integer',
                'name' => 'required|string|max:255',
                'journal_number' => 'nullable|string|max:50',
                'contract_id' => 'nullable|integer',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|in:active,archived,closed',
            ]);

            $journal = $this->journalService->createJournal($project, $validated, $user);

            return MobileResponse::success(
                $this->payloadService->mapJournal($journal, $user),
                trans_message('construction_journal.messages.created'),
                201
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.store.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.create_failed'), 500);
        }
    }

    public function show(ConstructionJournal $journal, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('view', $journal);

            $journal->load([
                'project',
                'contract',
                'createdBy',
                'entries' => function ($query): void {
                    $query->with([
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
                    ])->orderByDesc('entry_date')
                        ->orderByDesc('entry_number')
                        ->limit(10);
                },
            ])->loadCount([
                'entries',
                'entries as approved_entries_count' => fn ($query) => $query->approved(),
                'entries as submitted_entries_count' => fn ($query) => $query->submitted(),
                'entries as rejected_entries_count' => fn ($query) => $query->rejected(),
            ]);

            return MobileResponse::success($this->payloadService->mapJournal($journal, $user, true));
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.load_failed'), 500);
        }
    }

    public function update(ConstructionJournal $journal, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('update', $journal);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'journal_number' => 'nullable|string|max:50',
                'contract_id' => 'nullable|integer',
                'end_date' => 'nullable|date',
                'status' => 'nullable|in:active,archived,closed',
            ]);

            $journal = $this->journalService->updateJournal($journal, $validated);

            return MobileResponse::success(
                $this->payloadService->mapJournal($journal, $user),
                trans_message('construction_journal.messages.updated')
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.update.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.update_failed'), 500);
        }
    }

    public function entries(ConstructionJournal $journal, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return MobileResponse::error(trans_message('mobile_construction_journal.errors.unauthorized'), 401);
            }

            $this->mobileJournalService->assertJournalAccess($user, $journal);
            $this->authorize('view', $journal);

            return MobileResponse::success(
                $this->mobileJournalService->buildEntriesList($user, $journal, $request->only([
                    'status',
                    'date',
                    'date_from',
                    'date_to',
                    'page',
                    'per_page',
                ]))
            );
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage() ?: trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.construction_journal.entries.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'filters' => $request->only(['status', 'date', 'date_from', 'date_to', 'page', 'per_page']),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_construction_journal.errors.load_failed'), 500);
        }
    }
}
