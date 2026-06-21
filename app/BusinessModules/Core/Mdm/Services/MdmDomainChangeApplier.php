<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

use function trans_message;

class MdmDomainChangeApplier
{
    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmEntityGovernanceRegistry $governanceRegistry
    ) {}

    public function apply(MdmChangeRequest $changeRequest): Model
    {
        return match ($changeRequest->entity_type) {
            'contractor' => $this->applyContractor($changeRequest),
            'supplier' => $this->applySupplier($changeRequest),
            'budget_article' => $this->applyBudgetArticle($changeRequest),
            'responsibility_center' => $this->applyResponsibilityCenter($changeRequest),
            'project' => $this->applyProject($changeRequest),
            'contract' => $this->applyContract($changeRequest),
            default => throw ValidationException::withMessages([
                'entity_type' => [trans_message('mdm.errors.entity_not_supported')],
            ]),
        };
    }

    private function applyContractor(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);
        $this->guardUniqueValue($changeRequest, 'contractor', 'inn', $values['inn'] ?? null);

        return $this->persist($changeRequest, $values, true);
    }

    private function applySupplier(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);
        $this->guardUniqueValue($changeRequest, 'supplier', 'inn', $values['inn'] ?? null);
        $this->guardUniqueValue($changeRequest, 'supplier', 'code', $values['code'] ?? null);

        return $this->persist($changeRequest, $values, true);
    }

    private function applyBudgetArticle(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);
        $this->guardUniqueValue($changeRequest, 'budget_article', 'code', $values['code'] ?? null);

        return $this->persist($changeRequest, $values, true);
    }

    private function applyResponsibilityCenter(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);
        $this->guardUniqueValue($changeRequest, 'responsibility_center', 'code', $values['code'] ?? null);

        return $this->persist($changeRequest, $values, true);
    }

    private function applyProject(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);

        if ($changeRequest->action === 'create') {
            throw ValidationException::withMessages([
                'action' => [trans_message('mdm.errors.project_create_not_supported')],
            ]);
        }

        return $this->persist($changeRequest, $values, false);
    }

    private function applyContract(MdmChangeRequest $changeRequest): Model
    {
        $values = $this->allowedValues($changeRequest);

        if ($changeRequest->action === 'create') {
            throw ValidationException::withMessages([
                'action' => [trans_message('mdm.errors.contract_create_not_supported')],
            ]);
        }

        return $this->persist($changeRequest, $values, false);
    }

    private function persist(MdmChangeRequest $changeRequest, array $values, bool $allowCreate): Model
    {
        $organizationId = (int) $changeRequest->organization_id;
        $entityType = (string) $changeRequest->entity_type;

        if ($changeRequest->action === 'create') {
            if (! $allowCreate) {
                throw ValidationException::withMessages([
                    'action' => [trans_message('mdm.errors.create_not_supported')],
                ]);
            }

            $modelClass = $this->registry->get($entityType)['model'];

            return $modelClass::query()->create(array_merge($values, [
                'organization_id' => $organizationId,
            ]));
        }

        if ($changeRequest->entity_id === null) {
            throw ValidationException::withMessages([
                'entity_id' => [trans_message('mdm.errors.entity_required')],
            ]);
        }

        $model = $this->registry
            ->query($entityType, $organizationId)
            ->lockForUpdate()
            ->findOrFail((int) $changeRequest->entity_id);

        $model->fill($values);
        $model->save();

        return $model;
    }

    private function allowedValues(MdmChangeRequest $changeRequest): array
    {
        $values = $changeRequest->proposed_values ?? [];

        foreach (array_keys($values) as $field) {
            if (! is_string($field)) {
                unset($values[$field]);

                continue;
            }

            $policy = $this->governanceRegistry->classifyField((string) $changeRequest->entity_type, $field);
            if (! in_array($policy, ['change_request', 'direct'], true)) {
                throw ValidationException::withMessages([
                    $field => [trans_message('mdm.errors.field_not_allowed')],
                ]);
            }
        }

        return $values;
    }

    private function guardUniqueValue(MdmChangeRequest $changeRequest, string $entityType, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $query = $this->registry
            ->query($entityType, (int) $changeRequest->organization_id)
            ->where($field, $value);

        if ($changeRequest->entity_id !== null) {
            $query->whereKeyNot((int) $changeRequest->entity_id);
        }

        if (method_exists($query->getModel(), 'getDeletedAtColumn')) {
            $query->whereNull($query->getModel()->getDeletedAtColumn());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                $field => [trans_message('mdm.errors.duplicate_value')],
            ]);
        }
    }
}
