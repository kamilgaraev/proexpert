<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests\Concerns;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentAccessService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentReferenceAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait AuthorizesPaymentDocuments
{
    private function authorizePaymentAccess(string $permission, bool $source = true, bool $input = false): bool
    {
        $actor = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');
        if (! $actor instanceof User || $organizationId <= 0) {
            return false;
        }

        $projectId = null;
        if ($source) {
            $id = filter_var($this->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
            }
            $document = app(PaymentDocumentAccessService::class)->find($organizationId, $actor, $id, $permission);
            $projectId = $document->project_id === null ? null : (int) $document->project_id;
        }
        if ($input) {
            app(PaymentDocumentReferenceAccessService::class)->assertInput($organizationId, $actor, $permission, $this->all(), $projectId);
        }

        return true;
    }
}
