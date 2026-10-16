<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Responses;

use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

final class PublicSupplierRequestAccessResponse
{
    public static function blocked(SupplierRequest $supplierRequest, bool $forSubmit): ?JsonResponse
    {
        if ($supplierRequest->status === SupplierRequestStatusEnum::CANCELLED) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.cancelled'),
                Response::HTTP_GONE
            );
        }

        if (
            $supplierRequest->status === SupplierRequestStatusEnum::EXPIRED
            || $supplierRequest->hasExpiredPublicToken()
        ) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.expired'),
                Response::HTTP_GONE
            );
        }

        if ($forSubmit && $supplierRequest->status === SupplierRequestStatusEnum::RESPONDED) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.already_responded'),
                Response::HTTP_CONFLICT
            );
        }

        if ($forSubmit && ! $supplierRequest->canReceivePublicProposal()) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.not_accepting_responses'),
                Response::HTTP_CONFLICT
            );
        }

        return null;
    }
}
