<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConstructionJournalController extends Controller
{
    public function __construct(
        protected ConstructionJournalService $journalService,
        protected ConstructionJournalPayloadService $payloadService
    ) {
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('viewAny', [ConstructionJournal::class, $project]);

            $journals = $project->journals()
                ->with(['contract', 'createdBy', 'project'])
                ->withCount([
                    'entries',
                    'entries as approved_entries_count' => fn ($query) => $query->approved(),
                    'entries as submitted_entries_count' => fn ($query) => $query->submitted(),
                    'entries as rejected_entries_count' => fn ($query) => $query->rejected(),
                ])
                ->when($request->filled('status'), function ($query) use ($request): void {
                    $query->where('status', $request->string('status'));
                })
                ->orderByDesc('created_at')
                ->paginate((int) $request->input('per_page', 15));

            $data = collect($journals->items())
                ->map(fn (ConstructionJournal $journal): array => $this->payloadService->mapJournal($journal, $request->user()))
                ->values()
                ->all();

            return AdminResponse::paginated(
                $data,
                $this->payloadService->paginationMeta($journals),
                null,
                200,
                [
                    'total_journals' => $journals->total(),
                    'active_journals' => $project->journals()->where('status', 'active')->count(),
                    'archived_journals' => $project->journals()->where('status', 'archived')->count(),
                    'closed_journals' => $project->journals()->where('status', 'closed')->count(),
                    'available_actions' => $this->payloadService->buildJournalActions($project, $request->user()),
                ]
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $project->id,
                'status' => $request->input('status'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.load_failed'), 500);
        }
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('create', [ConstructionJournal::class, $project]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'journal_number' => 'nullable|string|max:50',
                'contract_id' => 'nullable|integer',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|in:active,archived,closed',
            ]);

            $journal = $this->journalService->createJournal($project, $validated, $request->user());

            return AdminResponse::success(
                $this->payloadService->mapJournal($journal, $request->user()),
                trans_message('construction_journal.messages.created'),
                201
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.store.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $project->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.create_failed'), 500);
        }
    }

    public function show(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
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

            return AdminResponse::success($this->payloadService->mapJournal($journal, $request->user(), true));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.load_failed'), 500);
        }
    }

    public function update(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('update', $journal);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'journal_number' => 'nullable|string|max:50',
                'contract_id' => 'nullable|integer',
                'end_date' => 'nullable|date',
                'status' => 'nullable|in:active,archived,closed',
            ]);

            $journal = $this->journalService->updateJournal($journal, $validated);

            return AdminResponse::success(
                $this->payloadService->mapJournal($journal, $request->user()),
                trans_message('construction_journal.messages.updated')
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.update.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'payload' => $request->all(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.update_failed'), 500);
        }
    }

    public function destroy(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('delete', $journal);

            $this->journalService->deleteJournal($journal);

            return AdminResponse::success(null, trans_message('construction_journal.messages.deleted'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.destroy.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.delete_failed'), 500);
        }
    }

    public function entries(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('view', $journal);

            $query = $journal->entries()
                ->with([
                    'journal',
                    'createdBy',
                    'approvedBy',
                    'scheduleTask',
                    'estimate',
                    'workVolumes.estimateItem',
                    'workVolumes.workType',
                    'workVolumes.measurementUnit',
                    'workers',
                    'equipment',
                    'materials.material',
                ]);

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('date')) {
                $query->whereDate('entry_date', $request->input('date'));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('entry_date', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('entry_date', '<=', $request->input('date_to'));
            }

            $entries = $query->orderByDesc('entry_date')
                ->orderByDesc('entry_number')
                ->paginate((int) $request->input('per_page', 20));

            $data = collect($entries->items())
                ->map(fn (ConstructionJournalEntry $entry): array => $this->payloadService->mapEntry($entry, $request->user()))
                ->values()
                ->all();

            return AdminResponse::paginated(
                $data,
                $this->payloadService->paginationMeta($entries),
                null,
                200,
                array_merge(
                    $this->payloadService->buildEntrySummary($journal),
                    ['available_actions' => $this->payloadService->buildJournalActions($journal, $request->user())]
                )
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.entries.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'journal_id' => $journal->id,
                'filters' => $request->only(['status', 'date', 'date_from', 'date_to', 'page', 'per_page']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('construction_journal.errors.load_failed'), 500);
        }
    }
}
