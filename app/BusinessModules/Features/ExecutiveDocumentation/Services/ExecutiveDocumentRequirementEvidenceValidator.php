<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileValidator;

final class ExecutiveDocumentRequirementEvidenceValidator
{
    public function violations(ExecutiveDocumentRequirement $requirement, ExecutiveDocumentVersion $version): array
    {
        if ((string) $requirement->applicability === 'not_applicable') {
            return [];
        }

        $basis = is_array($version->basis_snapshot) ? $version->basis_snapshot : [];
        $profile = $this->profileSnapshot($basis);
        $profileData = is_array($version->profile_snapshot) ? $version->profile_snapshot : [];
        $violations = [];
        if ($this->emptyValue($version->file_url)) {
            $violations[] = $this->violation('file_missing', 'file_url', trans_message('executive_requirements.file_missing'));
        }
        if ($this->emptyValue($version->content_hash)) {
            $violations[] = $this->violation('content_hash_missing', 'content_hash', trans_message('executive_requirements.content_hash_missing'));
        }
        $expectedType = trim((string) $requirement->profile_type);
        $actualType = trim((string) ($profile['type'] ?? $basis['document']['document_type'] ?? ''));

        if ($expectedType === '' || $actualType !== $expectedType) {
            $violations[] = $this->violation('profile_mismatch', 'document_type', trans_message('executive_requirements.profile_mismatch'));

            return $violations;
        }

        $rules = $this->rules($requirement);
        if (($rules['unresolved_conditions'] ?? []) !== []) {
            $violations[] = $this->violation('normative_conditions_unresolved', 'conditions', trans_message('executive_requirements.normative_conditions_unresolved'));
        }
        $expectedRevision = trim((string) ($rules['profile_revision'] ?? $rules['profile']['profile_revision'] ?? ''));
        if ($expectedRevision !== '' && $expectedRevision !== (string) ($profile['profile_revision'] ?? '')) {
            $violations[] = $this->violation('profile_revision_mismatch', 'profile_revision', trans_message('executive_requirements.profile_revision_mismatch'));
        }

        $ruleProfile = is_array($rules['profile'] ?? null) ? $rules['profile'] : [];
        $profileValidator = new ExecutiveDocumentProfileValidator;
        foreach ($profileValidator->missingRequiredFields($ruleProfile, $profileData) as $field => $label) {
            $violations[] = $this->violation('required_field_missing', (string) $field, trans_message('executive_requirements.required_field_missing', ['field' => $label]));
        }

        $document = is_array($basis['document'] ?? null) ? $basis['document'] : [];
        if (($ruleProfile['requires_work_type'] ?? false) === true && $this->emptyValue($document['work_type_id'] ?? null)) {
            $violations[] = $this->violation('work_type_missing', 'work_type_id', trans_message('executive_requirements.work_type_missing'));
        }
        if (($ruleProfile['requires_journal_entry'] ?? false) === true && $this->emptyValue($basis['journal_entry_id'] ?? null)) {
            $violations[] = $this->violation('journal_entry_missing', 'journal_entry_id', trans_message('executive_requirements.journal_entry_missing'));
        }

        $coverage = $basis['coverage'] ?? null;
        foreach ((array) ($requirement->coverage_scope ?? []) as $key => $expected) {
            if ($expected === null) {
                continue;
            }
            if (! is_array($coverage) || ! array_key_exists($key, $coverage)) {
                $violations[] = $this->violation('coverage_missing', "coverage.{$key}", trans_message('executive_requirements.coverage_missing'));
            } elseif ($key === 'quantity'
                ? ! (new ExecutiveDocumentCoverageDeclaration)->covers($coverage, ['quantity' => $expected, 'measurement_unit_id' => $requirement->coverage_scope['measurement_unit_id'] ?? null])
                : $coverage[$key] !== $expected) {
                $violations[] = $this->violation('coverage_mismatch', "coverage.{$key}", trans_message('executive_requirements.coverage_mismatch'));
            }
        }

        $expectedWorkType = $requirement->work_type_id;
        if ($expectedWorkType !== null && (int) ($basis['work_type_id'] ?? $document['work_type_id'] ?? 0) !== (int) $expectedWorkType) {
            $violations[] = $this->violation('work_type_mismatch', 'work_type_id', trans_message('executive_requirements.work_type_mismatch'));
        }
        $expectedCompletedWork = $requirement->completed_work_id;
        if ($expectedCompletedWork !== null && (int) ($basis['completed_work_id'] ?? 0) !== (int) $expectedCompletedWork) {
            $violations[] = $this->violation('completed_work_mismatch', 'completed_work_id', trans_message('executive_requirements.completed_work_mismatch'));
        }

        $relations = is_array($basis['relations'] ?? null) ? $basis['relations'] : [];
        foreach ($this->requiredRelations($rules) as $relation) {
            $key = (string) ($relation['key'] ?? '');
            $target = (string) ($relation['target'] ?? '');
            $present = $this->relationPresent($relations, $key, $target);
            if (! $present) {
                $violations[] = $this->violation('required_relation_missing', $key, trans_message('executive_requirements.required_relation_missing'));
            }
        }

        foreach ($this->requiredSignatories($rules) as $role => $authorityRequired) {
            $signatory = $this->signatoryForRole($document['signatories'] ?? [], $role);
            if ($signatory === null) {
                $violations[] = $this->violation('required_signatory_missing', 'signatories', trans_message('executive_requirements.required_signatory_missing', ['role' => $role]));

                continue;
            }
            if ($authorityRequired && ! $this->hasAuthority($signatory)) {
                $violations[] = $this->violation('authority_details_missing', 'signatories', trans_message('executive_requirements.authority_details_missing'));
            }
        }

        return $violations;
    }

    private function profileSnapshot(array $basis): array
    {
        $profile = $basis['profile'] ?? [];

        return is_array($profile) ? $profile : [];
    }

    private function rules(ExecutiveDocumentRequirement $requirement): array
    {
        $snapshot = is_array($requirement->rule_snapshot) ? $requirement->rule_snapshot : [];
        if (is_array($snapshot['profile'] ?? null)) {
            return $snapshot;
        }

        if (array_key_exists('required_signatories', $snapshot) || array_key_exists('required_relations', $snapshot)) {
            return ['profile' => [], ...$snapshot];
        }

        foreach (['type', 'fields', 'relations', 'requires_work_type', 'requires_journal_entry', 'profile_revision'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                return ['profile' => $snapshot];
            }
        }

        return ['profile' => [], ...$snapshot];
    }

    private function requiredRelations(array $rules): array
    {
        $result = [];
        $definitions = $rules['relations'] ?? ($rules['profile']['relations'] ?? []);
        if (! is_array($definitions)) {
            $definitions = [];
        }

        foreach ($definitions as $key => $relation) {
            if (is_string($relation)) {
                $relation = ['key' => $relation, 'required' => true];
            }
            if (! is_array($relation) || ($relation['required'] ?? false) !== true) {
                continue;
            }
            $result[] = ['key' => (string) ($relation['key'] ?? $key), 'target' => (string) ($relation['target'] ?? '')];
        }

        foreach (($rules['required_relations'] ?? []) as $key) {
            if (is_string($key)) {
                $result[] = ['key' => $key, 'target' => ''];
            }
        }

        return $result;
    }

    private function relationPresent(array $relations, string $key, string $target): bool
    {
        foreach ($relations as $relation) {
            if (! is_array($relation) || (string) ($relation['relation_type'] ?? '') !== $key) {
                continue;
            }
            if ($target === '' || (string) ($relation['target_type'] ?? '') === $target) {
                $targetId = (int) ($relation['target_id'] ?? 0);
                if ($targetId <= 0) {
                    continue;
                }
                if (in_array((string) ($relation['target_type'] ?? ''), ['journal_entry', 'material', 'supplier', 'project_material_delivery'], true)) {
                    if ((int) ($relation['domain_snapshot']['id'] ?? 0) === $targetId) {
                        return true;
                    }

                    continue;
                }
                $version = $relation['target_version'] ?? null;
                if (is_array($version) && (int) ($version['version_id'] ?? 0) > 0
                    && (int) ($version['document_id'] ?? 0) === $targetId
                    && is_string($version['content_hash'] ?? null) && trim($version['content_hash']) !== ''
                    && in_array($version['status'] ?? null, ['approved', 'transmitted'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function requiredSignatories(array $rules): array
    {
        $raw = $rules['required_signatories'] ?? ($rules['signatory_requirements'] ?? []);
        if (! is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $role => $requirement) {
            if (is_int($role) && is_string($requirement)) {
                $result[$requirement] = false;

                continue;
            }
            if (is_string($role)) {
                $result[$role] = is_array($requirement)
                    ? (($requirement['authority_required'] ?? $requirement['requires_authority'] ?? false) === true)
                    : $requirement === true;
            }
        }

        return $result;
    }

    private function signatoryForRole(mixed $signatories, string $role): ?array
    {
        if (! is_array($signatories)) {
            return null;
        }
        foreach ($signatories as $signatory) {
            if (is_array($signatory) && (string) ($signatory['role'] ?? $signatory['type'] ?? '') === $role) {
                return $signatory;
            }
        }

        return null;
    }

    private function hasAuthority(array $signatory): bool
    {
        foreach ([
            $signatory['authority_document'] ?? null,
            $signatory['name'] ?? $signatory['signer_name'] ?? null,
            $signatory['organization'] ?? $signatory['organization_name'] ?? null,
            $signatory['role'] ?? $signatory['type'] ?? null,
        ] as $value) {
            if (! is_string($value) || trim($value) === '' || trim($value) === '0') {
                return false;
            }
        }

        return true;
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === 0 || $value === '0';
    }

    private function violation(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
