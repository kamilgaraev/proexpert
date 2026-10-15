<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentAccessService;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ContractPaymentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }
        $contractId = filter_var($this->route('contract'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($contractId === false) {
            throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
        }
        $projectId = filter_var($this->input('project_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        app(PaymentDocumentAccessService::class)->contract(
            (int) $this->attributes->get('current_organization_id'), $actor, $contractId,
            'payments.invoice.view', $projectId === false ? null : $projectId,
        );

        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', new ProjectAccessibleRule],
        ];
    }

    public function projectId(): ?int
    {
        $projectId = $this->validated('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }
}
