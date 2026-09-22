<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\PrepareGeneralJournalDocumentRequest;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Services\ConstructionJournal\GeneralJournalDocumentQuery;
use App\Services\ConstructionJournal\GeneralJournalDocumentService;
use App\Services\ConstructionJournal\JournalExportWorkflowService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GeneralJournalDocumentController extends Controller
{
    public function __construct(
        private readonly GeneralJournalDocumentService $documents,
        private readonly GeneralJournalDocumentQuery $query,
        private readonly JournalExportWorkflowService $exports,
    ) {}

    public function show(Request $request, ConstructionJournal $journal, ?int $versionId = null): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => AdminResponse::success(
            $this->query->read($request->user(), $journal, $versionId),
        ));
    }

    public function prepare(PrepareGeneralJournalDocumentRequest $request, ConstructionJournal $journal): JsonResponse
    {
        return $this->respond(function () use ($request, $journal): JsonResponse {
            $version = $this->documents->prepare($request->user(), $journal, $request->validated());
            $export = $this->exports->request($request->user(), $journal, 'general', 'pdf',
                ['document_version_id' => $version->id], 'general-'.$version->id);
            return AdminResponse::success([
                'version' => $this->query->payload($version),
                'export' => $this->exports->payload($export, $request->user()),
            ], trans_message('construction_journal.messages.export_queued'), 202);
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (BusinessLogicException $exception) {
            $code = in_array($exception->getCode(), [403, 404, 409, 422], true) ? $exception->getCode() : 422;
            return AdminResponse::error(trans_message(match ($code) {
                403 => 'general_journal.access_denied', 404 => 'general_journal.not_found',
                409 => 'general_journal.stale_revision', default => 'general_journal.invalid_data',
            }), $code);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('general_journal.not_found'), 404);
        } catch (Throwable $exception) {
            Log::error('general_journal.request_failed', ['exception_class' => $exception::class]);
            return AdminResponse::error(trans_message('construction_journal.errors.export_failed'), 500);
        }
    }
}
