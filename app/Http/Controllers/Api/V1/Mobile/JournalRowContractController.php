<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\AssignJournalRowContractRequest;
use App\Http\Responses\MobileResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Services\ConstructionJournal\JournalContractAssignmentService;
use App\Services\Mobile\MobileConstructionJournalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class JournalRowContractController extends Controller
{
    public function update(AssignJournalRowContractRequest $request, ConstructionJournal $journal, ConstructionJournalEntry $entry, string $rowType, int $rowId, JournalContractAssignmentService $assignments, MobileConstructionJournalService $payload): JsonResponse
    {
        try {
            $this->authorize('assignContract', $entry);
            $data = $request->validated();
            $entry = $assignments->assign($journal, $entry, $rowType, $rowId, $data['contract_id'] === null ? null : (int) $data['contract_id'], $data['reason'], $request->user());

            return MobileResponse::success($payload->mapMobileEntry($entry, $request->user()), trans_message('construction_journal.messages.contract_assigned'));
        } catch (AuthorizationException $exception) {
            return MobileResponse::error(trans_message('construction_journal.errors.access_denied'), 403);
        } catch (ModelNotFoundException $exception) {
            return MobileResponse::error(trans_message('construction_journal.errors.contract_assignment_invalid'), 404);
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            $message = $exception->getMessage() === trans_message('completed_work.correction_required')
                ? trans_message('construction_journal.errors.contract_assignment_acted')
                : trans_message('construction_journal.errors.contract_assignment_failed');

            return MobileResponse::error($message, 422);
        } catch (\DomainException $exception) {
            return MobileResponse::error(trans_message('construction_journal.errors.contract_assignment_failed'), 422);
        } catch (\Throwable $exception) {
            Log::error('construction_journal.contract_assignment_failed', ['journal_id' => $journal->id, 'entry_id' => $entry->id, 'actor_id' => $request->user()?->id, 'exception' => $exception::class]);

            return MobileResponse::error(trans_message('construction_journal.errors.contract_assignment_failed'), 500);
        }
    }
}
