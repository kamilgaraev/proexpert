<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ConstructionJournal;
use App\Services\ConstructionJournal\ConstructionJournalFormOptionsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConstructionJournalFormOptionsController extends Controller
{
    public function __construct(private readonly ConstructionJournalFormOptionsService $options) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->options->buildJournalOptions(
                $request->user(),
                $request->integer('project_id'),
                $request->has('journal_id') ? $request->integer('journal_id') : null,
            ));
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('errors.unauthorized'), 403);
        }
    }

    public function show(Request $request, ConstructionJournal $journal): JsonResponse
    {
        try {
            $this->authorize('view', $journal);

            return AdminResponse::success($this->options->build($request->user(), $journal));
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('errors.unauthorized'), 403);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
