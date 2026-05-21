<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Support;

final class ExecutiveDocumentProfileRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $profiles = [];

        foreach ($this->definitions() as $type => $profile) {
            $profiles[] = $this->hydrateProfile($type, $profile);
        }

        return $profiles;
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $type): ?array
    {
        $definitions = $this->definitions();

        return isset($definitions[$type]) ? $this->hydrateProfile($type, $definitions[$type]) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function require(string $type): array
    {
        $profile = $this->find($type);

        if ($profile === null) {
            throw new \InvalidArgumentException("Unknown executive document profile [{$type}].");
        }

        return $profile;
    }

    /**
     * @return array<string, string>
     */
    public function missingRequiredFields(string $type, array $profileData): array
    {
        $profile = $this->require($type);
        $missing = [];

        foreach ($profile['fields'] as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }

            $value = data_get($profileData, $field['key']);

            if ($value === null || $value === '' || $value === []) {
                $missing[$field['key']] = $field['label'];
            }
        }

        return $missing;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            'hidden_work_act' => [
                'group' => 'acts',
                'regulatory_basis' => ['344/пр, приложение 3', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => true,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('presented_works', 'textarea', true),
                    $this->field('started_at', 'date', true),
                    $this->field('finished_at', 'date', true),
                    $this->field('materials_quality_documents', 'relation', false, ['target' => 'incoming_control_document', 'multiple' => true]),
                    $this->field('compliance_documents', 'relation', false, ['target' => 'geodetic_scheme', 'multiple' => true]),
                    $this->field('next_works_permission', 'textarea', true),
                    $this->field('actual_volume', 'text'),
                ],
                'relations' => [
                    $this->relation('quality_documents', 'incoming_control_document', true),
                    $this->relation('executive_schemes', 'geodetic_scheme', true),
                    $this->relation('journal_entry', 'journal_entry', true),
                ],
                'signatory_roles' => [
                    'developer_control_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'direct_work_executor',
                    'other',
                ],
            ],
            'axis_layout_act' => [
                'group' => 'acts',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => true,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('axis_layout_text', 'textarea', true),
                    $this->field('axis_fixing_text', 'textarea', true),
                    $this->field('executive_schemes', 'relation', false, ['target' => 'geodetic_scheme', 'multiple' => true]),
                    $this->field('actual_volume', 'text'),
                ],
                'relations' => [
                    $this->relation('executive_schemes', 'geodetic_scheme', true),
                    $this->relation('journal_entry', 'journal_entry', false),
                ],
                'signatory_roles' => [
                    'developer_control_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'axis_layout_executor',
                    'other',
                ],
            ],
            'geodetic_base_acceptance_act' => [
                'group' => 'acts',
                'regulatory_basis' => ['344/пр, приложение 1', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('geodetic_base_description', 'textarea', true),
                    $this->field('base_acceptance_documents', 'textarea', true),
                    $this->field('executive_geodetic_schemes', 'relation', false, ['target' => 'geodetic_scheme', 'multiple' => true]),
                ],
                'relations' => [
                    $this->relation('executive_geodetic_schemes', 'geodetic_scheme', true),
                ],
                'signatory_roles' => [
                    'developer_control_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'geodetic_base_executor',
                    'other',
                ],
            ],
            'responsible_structure_act' => [
                'group' => 'acts',
                'regulatory_basis' => ['344/пр, приложение 4', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => true,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('presented_structures', 'textarea', true),
                    $this->field('structure_location', 'textarea', true),
                    $this->field('started_at', 'date', true),
                    $this->field('finished_at', 'date', true),
                    $this->field('acceptance_decision', 'select', true, ['options' => ['accepted', 'next_works_allowed', 'remarks_required']]),
                    $this->field('actual_volume', 'text'),
                ],
                'relations' => [
                    $this->relation('hidden_work_acts', 'hidden_work_act', true),
                    $this->relation('geodetic_schemes', 'geodetic_scheme', true),
                    $this->relation('inspection_results', 'inspection_result', true),
                ],
                'signatory_roles' => [
                    'developer_control_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'structure_executor',
                    'other',
                ],
            ],
            'engineering_network_section_act' => [
                'group' => 'acts',
                'regulatory_basis' => ['344/пр, приложение 5', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => true,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('network_type', 'text', true),
                    $this->field('network_section_boundaries', 'textarea', true),
                    $this->field('technical_conditions', 'textarea', true),
                    $this->field('started_at', 'date', true),
                    $this->field('finished_at', 'date', true),
                    $this->field('compliance_conclusion', 'textarea', true),
                    $this->field('actual_volume', 'text'),
                ],
                'relations' => [
                    $this->relation('hidden_work_acts', 'hidden_work_act', true),
                    $this->relation('network_schemes', 'network_result_scheme', true),
                    $this->relation('tests', 'system_test_act', true),
                ],
                'signatory_roles' => [
                    'developer_control_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'network_executor',
                    'operating_company_representative',
                    'other',
                ],
            ],
            'construction_control_remark' => [
                'group' => 'remarks',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('remark_number', 'text', true),
                    $this->field('author_type', 'select', true, ['options' => ['developer', 'technical_customer', 'construction_control', 'designer']]),
                    $this->field('location_description', 'textarea', true),
                    $this->field('defect_description', 'textarea', true),
                    $this->field('normative_basis', 'textarea', true),
                    $this->field('responsible_organization', 'text', true),
                    $this->field('due_date', 'date', true),
                    $this->field('correction_method', 'textarea'),
                    $this->field('remark_status', 'select', true, ['options' => ['open', 'fixed', 'accepted', 'rejected']]),
                ],
                'relations' => [
                    $this->relation('source_document', 'executive_document', false),
                    $this->relation('confirmation_documents', 'executive_document', true),
                ],
                'signatory_roles' => [
                    'remark_author_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'designer_representative',
                    'other',
                ],
            ],
            'working_drawing_set' => [
                'group' => 'drawings',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => false,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('drawing_set_code', 'text', true),
                    $this->field('drawing_section', 'text', true),
                    $this->field('sheet_list', 'table', true),
                    $this->field('compliance_mark', 'textarea', true),
                    $this->field('approved_changes', 'textarea'),
                    $this->field('responsible_person', 'text', true),
                    $this->field('authority_document', 'text', true),
                    $this->field('drawing_set_status', 'select', true, ['options' => ['in_progress', 'review', 'accepted', 'correction_required']]),
                ],
                'relations' => [
                    $this->relation('related_acts', 'executive_document', true),
                    $this->relation('journal_entries', 'journal_entry', true),
                ],
                'signatory_roles' => [
                    'responsible_construction_person',
                    'construction_representative',
                    'designer_representative',
                    'other',
                ],
            ],
            'geodetic_scheme' => [
                'group' => 'schemes',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('scheme_number', 'text', true),
                    $this->field('survey_object', 'textarea', true),
                    $this->field('actual_position', 'textarea', true),
                    $this->field('design_position', 'textarea', true),
                    $this->field('survey_method', 'text', true),
                    $this->field('instruments_verification', 'textarea', true),
                    $this->field('actual_volume', 'text'),
                    $this->field('compliance_conclusion', 'textarea', true),
                ],
                'relations' => [
                    $this->relation('related_acts', 'executive_document', true),
                ],
                'signatory_roles' => [
                    'survey_executor',
                    'construction_representative',
                    'contractor_control_representative',
                    'other',
                ],
            ],
            'network_result_scheme' => [
                'group' => 'schemes',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('scheme_number', 'text', true),
                    $this->field('network_type', 'text', true),
                    $this->field('network_section_boundaries', 'textarea', true),
                    $this->field('actual_profile', 'textarea', true),
                    $this->field('design_values', 'textarea', true),
                    $this->field('technical_conditions', 'textarea'),
                    $this->field('compliance_conclusion', 'textarea', true),
                ],
                'relations' => [
                    $this->relation('tests', 'system_test_act', true),
                    $this->relation('network_section_acts', 'engineering_network_section_act', true),
                ],
                'signatory_roles' => [
                    'scheme_executor',
                    'construction_representative',
                    'contractor_control_representative',
                    'operating_company_representative',
                    'other',
                ],
            ],
            'system_test_act' => [
                'group' => 'tests',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => true,
                'fields' => [
                    $this->field('act_number', 'text', true),
                    $this->field('system_or_device', 'text', true),
                    $this->field('test_type', 'select', true, ['options' => ['hydraulic', 'pneumatic', 'electrical', 'ventilation', 'washing', 'blowing', 'disinfection', 'complex', 'other']]),
                    $this->field('test_program', 'textarea', true),
                    $this->field('test_conditions', 'textarea', true),
                    $this->field('measuring_instruments', 'textarea', true),
                    $this->field('actual_results', 'textarea', true),
                    $this->field('test_conclusion', 'select', true, ['options' => ['passed', 'retest_required', 'accepted_with_conditions']]),
                ],
                'relations' => [
                    $this->relation('related_acts', 'executive_document', true),
                    $this->relation('network_schemes', 'network_result_scheme', true),
                ],
                'signatory_roles' => [
                    'construction_representative',
                    'contractor_control_representative',
                    'testing_organization_representative',
                    'developer_control_representative',
                    'other',
                ],
            ],
            'inspection_result' => [
                'group' => 'tests',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => true,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('document_number', 'text', true),
                    $this->field('inspection_kind', 'select', true, ['options' => ['expertise', 'survey', 'laboratory_test', 'measurement', 'control_measurement', 'other']]),
                    $this->field('organization_name', 'text', true),
                    $this->field('inspection_object', 'textarea', true),
                    $this->field('methodology', 'textarea', true),
                    $this->field('sampled_at', 'date'),
                    $this->field('indicators', 'table', true),
                    $this->field('compliance_conclusion', 'textarea', true),
                    $this->field('recommendations', 'textarea'),
                ],
                'relations' => [
                    $this->relation('related_documents', 'executive_document', true),
                    $this->relation('incoming_control_documents', 'incoming_control_document', true),
                ],
                'signatory_roles' => [
                    'inspection_organization_representative',
                    'construction_representative',
                    'contractor_control_representative',
                    'other',
                ],
            ],
            'incoming_control_document' => [
                'group' => 'materials',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => false,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('document_number', 'text', true),
                    $this->field('received_at', 'date', true),
                    $this->field('checked_at', 'date', true),
                    $this->field('quality_document_kind', 'select', true, ['options' => ['passport', 'certificate', 'declaration', 'test_protocol', 'witness', 'instruction', 'other']]),
                    $this->field('material_name', 'text', true),
                    $this->field('manufacturer', 'text'),
                    $this->field('supplier', 'text'),
                    $this->field('batch_details', 'textarea', true),
                    $this->field('quantity', 'text', true),
                    $this->field('storage_place', 'text'),
                    $this->field('quality_document_details', 'textarea', true),
                    $this->field('incoming_control_result', 'select', true, ['options' => ['accepted', 'accepted_with_restrictions', 'rejected']]),
                    $this->field('quality_remarks', 'textarea'),
                ],
                'relations' => [
                    $this->relation('material_reference', 'material', false),
                    $this->relation('supplier_document', 'supplier', false),
                    $this->relation('laboratory_protocols', 'inspection_result', true),
                    $this->relation('used_in_documents', 'executive_document', true),
                ],
                'signatory_roles' => [
                    'construction_representative',
                    'contractor_control_representative',
                    'supplier_representative',
                    'other',
                ],
            ],
            'work_journal' => [
                'group' => 'journals',
                'regulatory_basis' => ['344/пр', 'СП 48.13330.2019'],
                'requires_work_type' => false,
                'requires_journal_entry' => false,
                'fields' => [
                    $this->field('journal_kind', 'select', true, ['options' => ['general', 'special', 'designer_supervision', 'other']]),
                    $this->field('journal_number', 'text', true),
                    $this->field('opened_at', 'date', true),
                    $this->field('closed_at', 'date'),
                    $this->field('journal_organization', 'text', true),
                    $this->field('responsible_person', 'text', true),
                    $this->field('journal_period', 'text', true),
                    $this->field('journal_status', 'select', true, ['options' => ['active', 'review', 'closed', 'correction_required']]),
                ],
                'relations' => [
                    $this->relation('journal_entries', 'journal_entry', true),
                    $this->relation('related_documents', 'executive_document', true),
                ],
                'signatory_roles' => [
                    'journal_responsible_person',
                    'construction_representative',
                    'contractor_control_representative',
                    'developer_control_representative',
                    'other',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function field(string $key, string $type, bool $required = false, array $extra = []): array
    {
        return [
            'key' => $key,
            'label' => trans_message("executive_documentation.profile_fields.{$key}"),
            'type' => $type,
            'required' => $required,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relation(string $key, string $target, bool $multiple): array
    {
        return [
            'key' => $key,
            'label' => trans_message("executive_documentation.profile_relations.{$key}"),
            'target' => $target,
            'multiple' => $multiple,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function hydrateProfile(string $type, array $profile): array
    {
        return [
            'type' => $type,
            'label' => trans_message("executive_documentation.document_types.{$type}"),
            'group' => $profile['group'],
            'group_label' => trans_message("executive_documentation.profile_groups.{$profile['group']}"),
            'regulatory_basis' => $profile['regulatory_basis'],
            'requires_work_type' => $profile['requires_work_type'],
            'requires_journal_entry' => $profile['requires_journal_entry'],
            'fields' => $profile['fields'],
            'relations' => $profile['relations'],
            'signatory_roles' => array_map(static fn (string $role): array => [
                'key' => $role,
                'label' => trans_message("executive_documentation.signatory_roles.{$role}"),
            ], $profile['signatory_roles']),
        ];
    }
}
