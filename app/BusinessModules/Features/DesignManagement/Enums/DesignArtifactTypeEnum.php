<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignArtifactTypeEnum: string
{
    case MODEL = 'model';
    case DRAWING_SET = 'drawing_set';
    case SOURCE_DATA = 'source_data';
    case SURVEY_REPORT = 'survey_report';
    case OTHER = 'other';
}
