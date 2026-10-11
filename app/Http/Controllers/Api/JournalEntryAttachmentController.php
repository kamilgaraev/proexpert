<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConstructionJournal\StoreEntryAttachmentRequest;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalEntryAttachment;
use App\Services\ConstructionJournal\EntryAttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class JournalEntryAttachmentController extends Controller
{
    public function __construct(private readonly EntryAttachmentService $attachments) {}

    public function index(Request $request, ConstructionJournal $journal, ConstructionJournalEntry $entry): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->attachments->list($request->user(), $entry, $journal));
    }

    public function store(StoreEntryAttachmentRequest $request, ConstructionJournal $journal, ConstructionJournalEntry $entry): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->attachments->upload($request->user(), $entry, $request->file('file'), $journal), 201);
    }

    public function download(Request $request, ConstructionJournal $journal, ConstructionJournalEntry $entry, JournalEntryAttachment $attachment): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->attachments->download($request->user(), $entry, $attachment, $journal));
    }

    private function respond(Request $request, callable $operation, int $status = 200): JsonResponse
    {
        if (! $request->user()) {
            return AdminResponse::error(trans_message('errors.unauthorized'), 401);
        }
        try {
            return AdminResponse::success($operation(), null, $status);
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('construction_journal.errors.access_denied'), 403);
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('construction_journal.errors.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('construction_journal.attachment_operation_failed', ['user_id' => $request->user()->id, 'error_class' => $exception::class]);

            return AdminResponse::error(trans_message('construction_journal.errors.attachment_operation_failed'), 500);
        }
    }
}
