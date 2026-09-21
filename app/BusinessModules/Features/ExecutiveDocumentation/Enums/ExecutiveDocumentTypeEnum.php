<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Enums;

enum ExecutiveDocumentTypeEnum: string
{
    case HIDDEN_WORK_ACT = 'hidden_work_act';
    case AXIS_LAYOUT_ACT = 'axis_layout_act';
    case GEODETIC_BASE_ACCEPTANCE_ACT = 'geodetic_base_acceptance_act';
    case RESPONSIBLE_STRUCTURE_ACT = 'responsible_structure_act';
    case ENGINEERING_NETWORK_SECTION_ACT = 'engineering_network_section_act';
    case CONSTRUCTION_CONTROL_REMARK = 'construction_control_remark';
    case WORKING_DRAWING_SET = 'working_drawing_set';
    case GEODETIC_SCHEME = 'geodetic_scheme';
    case NETWORK_RESULT_SCHEME = 'network_result_scheme';
    case SYSTEM_TEST_ACT = 'system_test_act';
    case INSPECTION_RESULT = 'inspection_result';
    case INCOMING_CONTROL_DOCUMENT = 'incoming_control_document';
    case QUALITY_PASSPORT = 'quality_passport';
    case INCOMING_BATCH_CONTROL = 'incoming_batch_control';
    case WORK_JOURNAL = 'work_journal';
    case WELDING_WORK_JOURNAL = 'welding_work_journal';
    case CONCRETE_WORK_JOURNAL = 'concrete_work_journal';
    case PILE_WORK_JOURNAL = 'pile_work_journal';
    case INSTALLATION_WORK_JOURNAL = 'installation_work_journal';
    case ANTICORROSION_WORK_JOURNAL = 'anticorrosion_work_journal';
    case DESIGNER_SUPERVISION_JOURNAL = 'designer_supervision_journal';
    case EXECUTIVE_SCHEME = 'executive_scheme';
    case MATERIAL_CERTIFICATE = 'material_certificate';
    case TEST_PROTOCOL = 'test_protocol';
    case WORK_LOG_EXTRACT = 'work_log_extract';
    case PHOTO_REPORT = 'photo_report';
    case HANDOVER_PACKAGE = 'handover_package';
    case OTHER = 'other';

    public function label(): string
    {
        return trans_message("executive_documentation.document_types.{$this->value}");
    }
}
