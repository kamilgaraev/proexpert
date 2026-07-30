<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\Domain\Authorization\Http\Middleware\InterfaceRequestProvenance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator as IlluminateValidator;

abstract class ReportFormRequest extends FormRequest
{
    abstract public function rules(): array;

    final public function authorize(): bool
    {
        return true;
    }

    final protected function forbiddenClientFieldsRules(): array
    {
        return [
            'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'permission' => ['prohibited'],
            'permissions' => ['prohibited'],
            'formula_version' => ['prohibited'],
            'source_hash' => ['prohibited'],
            'snapshot_id' => ['prohibited'],
            'definition_hash' => ['prohibited'],
            'query_hash' => ['prohibited'],
        ];
    }

    final protected function subscriptionForbiddenClientFieldsRules(): array
    {
        return [
            ...$this->forbiddenClientFieldsRules(),
            'owner_id' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'report_code' => ['prohibited'],
            'channel' => ['prohibited'],
            'recipients' => ['prohibited'],
            'status' => ['prohibited'],
            'next_run_at' => ['prohibited'],
            'execution_input' => ['prohibited'],
            'contract_version' => ['prohibited'],
            'consecutive_failures' => ['prohibited'],
            'run_id' => ['prohibited'],
            'export_id' => ['prohibited'],
            'notification_receipt_id' => ['prohibited'],
        ];
    }

    final protected function canonicalUlidRules(): array
    {
        return ['ulid', 'regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'];
    }

    protected function acceptedBodyFields(): array
    {
        return [];
    }

    protected function acceptedQueryFields(): array
    {
        return [];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field;
    }

    public function after(): array
    {
        return [
            function (IlluminateValidator $validator): void {
                $forbidden = array_keys($this->forbiddenClientFieldsRules());

                foreach (array_keys($this->request->all()) as $field) {
                    if ($this->isTrustedServerDerivedInterface($field)) {
                        continue;
                    }

                    if (! in_array($field, $this->acceptedBodyFields(), true)
                        && ! in_array($field, $forbidden, true)) {
                        $validator->errors()->add($field, trans_message('reports.errors.report_request_invalid'));
                    }
                }

                foreach (array_keys($this->query->all()) as $field) {
                    if ($this->isTrustedServerDerivedInterface($field)) {
                        continue;
                    }

                    if (! in_array($field, $this->acceptedQueryFields(), true)
                        && ! in_array($field, $forbidden, true)) {
                        $validator->errors()->add($field, trans_message('reports.errors.report_request_invalid'));
                    }
                }
            },
        ];
    }

    private function isTrustedServerDerivedInterface(string $field): bool
    {
        return $field === 'current_interface'
            && InterfaceRequestProvenance::isTrustedServerDerived($this);
    }

    protected function failedValidation(Validator $validator): never
    {
        $fields = array_map(
            static fn (string $field): string => explode('.', $field, 2)[0],
            $validator->errors()->keys(),
        );
        $fields = array_values(array_unique(array_intersect(
            $fields,
            array_keys($this->rules()),
        )));
        $fields = array_map(
            fn (string $field): string => $this->safeValidationFieldKey($field),
            $fields,
        );
        sort($fields);

        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            $fields === [] ? [] : ['fields' => $fields],
        );
    }
}
