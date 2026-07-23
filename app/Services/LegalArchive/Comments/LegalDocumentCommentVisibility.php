<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Comments;

enum LegalDocumentCommentVisibility: string
{
    case INTERNAL = 'internal';
    case ALL_PARTIES = 'all_parties';
    case AUTHOR_AND_RESPONSIBLE = 'author_and_responsible';
}
