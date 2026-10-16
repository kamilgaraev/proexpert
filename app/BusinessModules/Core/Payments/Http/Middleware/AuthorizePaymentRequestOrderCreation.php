<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Middleware;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use Illuminate\Http\Request;

final class AuthorizePaymentRequestOrderCreation extends AuthorizeMiddleware
{
    protected function resolveContext(Request $request, ?string $contextType, ?string $contextParam): ?array
    {
        $organizationId = $this->getOrganizationFromRequest($request);
        $sourceId = filter_var($request->input('source_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($organizationId === null
            || $request->input('document_type') !== PaymentDocumentType::PAYMENT_ORDER->value
            || $request->input('source_type') !== PaymentDocument::class
            || $sourceId === false) {
            return parent::resolveContext($request, $contextType, $contextParam);
        }

        $source = PaymentDocument::query()->useWritePdo()
            ->where('organization_id', $organizationId)
            ->where('document_type', PaymentDocumentType::PAYMENT_REQUEST->value)
            ->find($sourceId);
        if (! $source instanceof PaymentDocument || $source->project_id === null) {
            return parent::resolveContext($request, $contextType, $contextParam);
        }

        $data = $request->all();
        $projectId = array_key_exists('project_id', $data)
            ? filter_var($data['project_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : (int) $source->project_id;
        if ($projectId === false) {
            return parent::resolveContext($request, $contextType, $contextParam);
        }

        return [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ];
    }
}
