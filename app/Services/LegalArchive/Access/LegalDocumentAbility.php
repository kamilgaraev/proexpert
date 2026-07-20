<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

enum LegalDocumentAbility: string
{
    case VIEW = 'view';
    case COMMENT = 'comment';
    case APPROVE = 'approve';
    case SIGN = 'sign';
    case DOWNLOAD = 'download';
    case MANAGE = 'manage';

    public function permission(): string
    {
        return match ($this) {
            self::VIEW => 'legal_archive.view',
            self::COMMENT => 'legal_archive.view',
            self::APPROVE => 'legal_archive.workflow.approve',
            self::SIGN => 'legal_archive.signatures.sign',
            self::DOWNLOAD => 'legal_archive.files.download',
            self::MANAGE => 'legal_archive.external_access.manage',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
