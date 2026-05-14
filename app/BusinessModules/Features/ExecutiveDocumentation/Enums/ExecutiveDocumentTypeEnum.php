<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Enums;

enum ExecutiveDocumentTypeEnum: string
{
    case HIDDEN_WORK_ACT = 'hidden_work_act';
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
