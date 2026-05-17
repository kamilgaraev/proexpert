<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Enums;

enum ProjectMaterialDeliveryStatusEnum: string
{
    case REQUESTED = 'requested';
    case PROCESSING = 'processing';
    case RESERVED = 'reserved';
    case PREPARING = 'preparing';
    case IN_TRANSIT = 'in_transit';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case DELIVERED = 'delivered';
    case ACCEPTED = 'accepted';
    case PROBLEM = 'problem';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return trans_message('basic_warehouse.project_material_deliveries.statuses.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::REQUESTED, self::PROCESSING, self::PREPARING => 'warning',
            self::RESERVED, self::IN_TRANSIT => 'info',
            self::PARTIALLY_DELIVERED, self::DELIVERED => 'primary',
            self::ACCEPTED => 'success',
            self::PROBLEM, self::CANCELLED => 'error',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::CANCELLED], true);
    }

    public function canBeReceived(): bool
    {
        return in_array($this, [self::IN_TRANSIT, self::PARTIALLY_DELIVERED, self::DELIVERED], true);
    }
}
