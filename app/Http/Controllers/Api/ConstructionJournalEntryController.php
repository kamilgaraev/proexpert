<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConstructionJournalEntryController extends Controller
{
    public function __construct(
        protected ConstructionJournalService $journalService,
        protected JournalApprovalService $approvalService
    ) {}

    /**
     * Создать запись в журнале
     */
    public function store(Request $request, ConstructionJournal $journal): JsonResponse
    {
        $this->authorize('create', [ConstructionJournalEntry::class, $journal]);

        $validated = $request->validate([
            'schedule_task_id' => 'nullable|exists:schedule_tasks,id',
            'entry_date' => 'required|date',
            'entry_number' => 'nullable|integer|min:1',
            'work_description' => 'required|string',
            'weather_conditions' => 'nullable|array',
            'weather_conditions.temperature' => 'nullable|numeric',
            'weather_conditions.precipitation' => 'nullable|string',
            'weather_conditions.wind_speed' => 'nullable|numeric',
            'problems_description' => 'nullable|string',
            'safety_notes' => 'nullable|string',
            'visitors_notes' => 'nullable|string',
            'quality_notes' => 'nullable|string',
            'work_volumes' => 'nullable|array',
            'work_volumes.*.estimate_item_id' => 'nullable|exists:estimate_items,id',
            'work_volumes.*.work_type_id' => 'nullable|exists:work_types,id',
            'work_volumes.*.quantity' => 'required|numeric|min:0',
            'work_volumes.*.measurement_unit_id' => 'nullable|exists:measurement_units,id',
            'work_volumes.*.notes' => 'nullable|string',
            'workers' => 'nullable|array',
            'workers.*.specialty' => 'required|string',
            'workers.*.workers_count' => 'required|integer|min:1',
            'workers.*.hours_worked' => 'nullable|numeric|min:0',
            'equipment' => 'nullable|array',
            'equipment.*.equipment_name' => 'required|string',
            'equipment.*.equipment_type' => 'nullable|string',
            'equipment.*.quantity' => 'nullable|integer|min:1',
            'equipment.*.hours_used' => 'nullable|numeric|min:0',
            'materials' => 'nullable|array',
            'materials.*.material_id' => 'nullable|exists:materials,id',
            'materials.*.material_name' => 'required|string',
            'materials.*.quantity' => 'required|numeric|min:0',
            'materials.*.measurement_unit' => 'required|string',
            'materials.*.notes' => 'nullable|string',
        ]);

        $entry = $this->journalService->createEntry($journal, $validated, $request->user());

        return response()->json($entry, 201);
    }

    /**
     * Получить детали записи
     */
    public function show(ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        $entry->load([
            'journal',
            'scheduleTask',
            'createdBy',
            'approvedBy',
            'workVolumes.estimateItem',
            'workVolumes.workType',
            'workVolumes.measurementUnit',
            'workers',
            'equipment',
            'materials.material'
        ]);

        return response()->json($entry);
    }

    /**
     * Обновить запись
     */
    public function update(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('update', $entry);

        if (!$entry->canBeEdited()) {
            return response()->json([
                'message' => 'Запись в текущем статусе не может быть отредактирована'
            ], 422);
        }

        $validated = $request->validate([
            'schedule_task_id' => 'sometimes|nullable|exists:schedule_tasks,id',
            'entry_date' => 'sometimes|date',
            'work_description' => 'sometimes|string',
            'weather_conditions' => 'nullable|array',
            'problems_description' => 'nullable|string',
            'safety_notes' => 'nullable|string',
            'visitors_notes' => 'nullable|string',
            'quality_notes' => 'nullable|string',
            'work_volumes' => 'nullable|array',
            'workers' => 'nullable|array',
            'equipment' => 'nullable|array',
            'materials' => 'nullable|array',
        ]);

        $entry = $this->journalService->updateEntry($entry, $validated);

        return response()->json($entry);
    }

    /**
     * Удалить запись
     */
    public function destroy(ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('delete', $entry);

        if (!$entry->canBeEdited()) {
            return response()->json([
                'message' => 'Запись в текущем статусе не может быть удалена'
            ], 422);
        }

        $this->journalService->deleteEntry($entry);

        return response()->json(['message' => 'Запись успешно удалена'], 200);
    }

    /**
     * Отправить запись на утверждение
     */
    public function submit(ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('update', $entry);

        try {
            $entry = $this->approvalService->submitForApproval($entry);
            return response()->json($entry);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Утвердить запись
     */
    public function approve(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('approve', $entry);

        try {
            $entry = $this->approvalService->approve($entry, $request->user());
            return response()->json($entry);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Отклонить запись
     */
    public function reject(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('approve', $entry);

        $validated = $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        try {
            $entry = $this->approvalService->reject($entry, $request->user(), $validated['reason']);
            return response()->json($entry);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}

