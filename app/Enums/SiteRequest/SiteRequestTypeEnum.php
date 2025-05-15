<?php

namespace App\Enums\SiteRequest;

use App\Traits\Enums\HasValues;

enum SiteRequestTypeEnum: string
{
    use HasValues;

    case MATERIAL_REQUEST = 'material_request'; // Заявка на материалы
    case INFO_REQUEST = 'info_request';         // Запрос информации
    case ISSUE_REPORT = 'issue_report';         // Сообщение о проблеме
    case WORK_ORDER = 'work_order';             // Заявка на выполнение работ (наряд)
    case OTHER = 'other';                     // Другое
} 