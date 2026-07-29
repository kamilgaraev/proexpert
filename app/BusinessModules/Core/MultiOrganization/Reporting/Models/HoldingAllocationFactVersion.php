<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class HoldingAllocationFactVersion extends Model
{
    protected $table = 'holding_allocation_fact_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'holding_id' => 'integer',
            'contributor_organization_id' => 'integer',
            'counterparty_organization_id' => 'integer',
            'project_id' => 'integer',
            'contract_id' => 'integer',
            'allocation_id' => 'integer',
            'linked_parent_allocation_id' => 'integer',
            'linked_incoming_minor' => 'integer',
            'linked_outgoing_minor' => 'integer',
            'source_id' => 'integer',
            'source_version' => 'integer',
            'amount_minor' => 'integer',
            'allocated_amount_minor' => 'integer',
            'contract_amount_minor' => 'integer',
            'allocated_percentage' => 'decimal:8',
            'recognized_on' => 'immutable_date',
            'projected_at' => 'immutable_datetime',
            'source_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('holding_fact_immutable'));
        self::deleting(static fn (): never => throw new LogicException('holding_fact_immutable'));
    }
}
