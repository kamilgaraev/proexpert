<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Errors;

use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportErrorResponseFactory
{
    public function __construct(
        private ReportErrorCatalog $catalog,
    ) {}

    public function make(
        ReportContractException $exception,
        string $correlationId,
    ): JsonResponse {
        $descriptor = $this->catalog->descriptor($exception->errorCode);

        return AdminResponse::error(
            trans_message($descriptor->translationKey),
            $descriptor->httpStatus,
            null,
            [
                'code' => $exception->errorCode->value,
                'correlation_id' => $correlationId,
                'retryable' => $descriptor->retryable,
                'details' => $exception->safeFields,
            ],
        );
    }
}
