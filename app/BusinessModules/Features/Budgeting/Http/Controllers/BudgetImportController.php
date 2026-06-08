<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetImportCommitRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetImportPreviewRequest;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

final class BudgetImportController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetImportService $service)
    {
    }

    public function preview(BudgetImportPreviewRequest $request, string $versionUuid): JsonResponse
    {
        try {
            $file = $request->file('file');
            if (!$file instanceof UploadedFile) {
                throw new DomainException(trans_message('budgeting.validation.file'));
            }

            $batch = $this->service->preview($this->user($request), $versionUuid, $file, $request->validated());

            return AdminResponse::success($this->service->batchToArray($batch), trans_message('budgeting.import.preview_ready'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function commit(BudgetImportCommitRequest $request, string $versionUuid): JsonResponse
    {
        try {
            $input = $request->validated();
            $batch = $this->service->commit(
                $this->user($request),
                $versionUuid,
                (string) $input['import_batch_id'],
                (string) $input['mode']
            );

            return AdminResponse::success($this->service->batchToArray($batch), trans_message('budgeting.import.committed'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }
}
