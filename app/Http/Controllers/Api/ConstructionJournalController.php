<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Models\ConstructionJournal;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConstructionJournalController extends Controller
{
    public function __construct(
        protected ConstructionJournalService $journalService
    ) {}

    /**
     * Список журналов проекта
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', [ConstructionJournal::class, $project]);

        $journals = $project->journals()
            ->with(['contract', 'createdBy'])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($journals);
    }

    /**
     * Создать журнал для проекта
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('create', [ConstructionJournal::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'journal_number' => 'nullable|string|max:50',
            'contract_id' => 'nullable|exists:contracts,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,archived,closed',
        ]);

        $journal = $this->journalService->createJournal($project, $validated, $request->user());

        return response()->json($journal, 201);
    }

    /**
     * Получить детали журнала
     */
    public function show(ConstructionJournal $journal): JsonResponse
    {
        $this->authorize('view', $journal);

        $journal->load([
            'project',
            'contract',
            'createdBy',
            'entries' => function ($query) {
                $query->with(['createdBy', 'approvedBy', 'scheduleTask'])
                    ->orderBy('entry_date', 'desc')
                    ->orderBy('entry_number', 'desc')
                    ->limit(10);
            }
        ]);

        $journal->setAttribute('total_entries', $journal->getTotalEntriesCount());
        $journal->setAttribute('approved_entries', $journal->getApprovedEntriesCount());

        return response()->json($journal);
    }

    /**
     * Обновить журнал
     */
    public function update(Request $request, ConstructionJournal $journal): JsonResponse
    {
        $this->authorize('update', $journal);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'journal_number' => 'nullable|string|max:50',
            'end_date' => 'nullable|date',
            'status' => 'nullable|in:active,archived,closed',
        ]);

        $journal = $this->journalService->updateJournal($journal, $validated);

        return response()->json($journal);
    }

    /**
     * Удалить журнал
     */
    public function destroy(ConstructionJournal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);

        $this->journalService->deleteJournal($journal);

        return response()->json(['message' => 'Журнал успешно удален'], 200);
    }

    /**
     * Получить записи журнала
     */
    public function entries(Request $request, ConstructionJournal $journal): JsonResponse
    {
        $this->authorize('view', $journal);

        $query = $journal->entries()
            ->with([
                'createdBy',
                'approvedBy',
                'scheduleTask',
                'workVolumes',
                'workers',
                'equipment',
                'materials'
            ]);

        // Фильтры
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

        $entries = $query->orderBy('entry_date', 'desc')
            ->orderBy('entry_number', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($entries);
    }
}

