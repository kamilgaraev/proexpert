<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

enum LegalDocumentAbility: string
{
    case VIEW = 'view';
    case COMMENT = 'comment';
    case APPROVE = 'approve';
    case REQUEST_SIGNATURE = 'request_signature';
    case SIGN = 'sign';
    case VERIFY_SIGNATURE = 'verify_signature';
    case DOWNLOAD = 'download';
    case EDIT = 'edit';
    case MANAGE = 'manage';

    public function permission(): string
    {
        return match ($this) {
            self::VIEW => 'legal_archive.view',
            self::COMMENT => 'legal_archive.view',
            self::APPROVE => 'legal_archive.workflow.approve',
            self::REQUEST_SIGNATURE => 'legal_archive.signatures.request',
            self::SIGN => 'legal_archive.signatures.sign',
            self::VERIFY_SIGNATURE => 'legal_archive.signatures.verify',
            self::DOWNLOAD => 'legal_archive.files.download',
            self::EDIT => 'legal_archive.editor.edit',
            self::MANAGE => 'legal_archive.external_access.manage',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
