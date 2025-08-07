<?php

namespace App\Enums\SiteRequest;

use App\Traits\Enums\HasValues;

enum SiteRequestStatusEnum: string
{
    use HasValues;

    case DRAFT = 'draft'; // Черновик
    case PENDING = 'pending'; // Ожидает рассмотрения
    case SUBMITTED = 'submitted'; // Подана
    case APPROVED = 'approved'; // Одобрена
    case REJECTED = 'rejected'; // Отклонена
    case IN_PROGRESS = 'in_progress'; // В работе
    case COMPLETED = 'completed'; // Завершена
    case ON_HOLD = 'on_hold'; // Приостановлена
} 