<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\IncludeJournalEntryRequest;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournalEntry;
use App\Services\ConstructionJournal\JournalEntryReportingService;
use App\Services\Mobile\MobileConstructionJournalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalEntryReportingController extends Controller
{
    public function __construct(
        private readonly JournalEntryReportingService $reporting,
        private readonly MobileConstructionJournalService $payload,
    ) {}

    public function targets(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            return MobileResponse::success($this->reporting->targets($request->user(), $entry));
        } catch (AuthorizationException) {
            return MobileResponse::error(trans_message('construction_journal.errors.access_denied'), 403);
        } catch (DomainException) {
            return MobileResponse::error(trans_message('construction_journal.errors.reporting_unavailable'), 422);
        }
    }

    public function include(IncludeJournalEntryRequest $request, ConstructionJournalEntry $entry): JsonResponse
    {
        try {
            $data = $request->validated();
            $report = $this->reporting->include($request->user(), $entry, (int) $data['target_journal_id'], $data);
            return MobileResponse::success($this->payload->mapMobileEntry($report, $request->user()), trans_message('construction_journal.messages.entry_included'));
        } catch (AuthorizationException) {
            return MobileResponse::error(trans_message('construction_journal.errors.access_denied'), 403);
        } catch (DomainException) {
            return MobileResponse::error(trans_message('construction_journal.errors.reporting_unavailable'), 422);
        }
    }
}
