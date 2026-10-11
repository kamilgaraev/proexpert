<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\IncludeJournalEntryRequest;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournalEntry;
use App\Services\ConstructionJournal\JournalEntryReportingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalEntryReportingController extends Controller
{
    public function __construct(
        private readonly JournalEntryReportingService $reporting,
        private readonly ConstructionJournalPayloadService $payloads,
    ) {}

    public function targets(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            return AdminResponse::success($this->reporting->targets($request->user(), $entry));
        } catch (DomainException) {
            return AdminResponse::error(trans_message('construction_journal.errors.reporting_unavailable'), 422);
        }
    }

    public function include(IncludeJournalEntryRequest $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $data = $request->validated();
            $report = $this->reporting->include($request->user(), $entry, (int) $data['target_journal_id'], $data);
            return AdminResponse::success($this->payloads->mapEntry($report, $request->user()), trans_message('construction_journal.messages.entry_included'));
        } catch (DomainException) {
            return AdminResponse::error(trans_message('construction_journal.errors.reporting_unavailable'), 422);
        }
    }
}
