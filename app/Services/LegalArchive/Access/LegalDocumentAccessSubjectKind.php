<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

enum LegalDocumentAccessSubjectKind: string
{
    case INTERNAL_USER = 'internal_user';
    case INTERNAL_ROLE = 'internal_role';
    case EXTERNAL_ORGANIZATION = 'external_org';
    case EXTERNAL_USER = 'external_user';
}
