<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class HoldingPaymentTransactionEventVersion extends Model
{
    public $timestamps = false;

    protected $table = 'holding_payment_transaction_event_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'payment_document_id' => 'integer',
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'contract_id' => 'integer',
            'document_organization_id' => 'integer',
            'document_project_id' => 'integer',
            'contract_organization_id' => 'integer',
            'contract_project_id' => 'integer',
            'amount' => 'decimal:2',
            'active' => 'boolean',
            'recognized_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'history_complete' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('holding_payment_event_immutable'));
        self::deleting(static fn (): never => throw new LogicException('holding_payment_event_immutable'));
    }
}
