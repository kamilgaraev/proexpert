<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChangeClaimHistoryCheckpoint extends Model
{
    public $timestamps = false;

    protected $table = 'change_claim_history_checkpoints';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claim_link_count' => 'integer',
            'claim_link_watermark_id' => 'integer',
            'completed_at' => 'immutable_datetime',
            'change_request_count' => 'integer',
            'change_request_watermark_id' => 'integer',
            'ledger_count' => 'integer',
            'ledger_watermark_id' => 'integer',
            'unprojectable_legacy_count' => 'integer',
            'version_count' => 'integer',
            'version_watermark_id' => 'integer',
            'workflow_event_count' => 'integer',
            'workflow_event_watermark_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('change_claim_history_checkpoint_immutable');
        });
        static::deleting(static function (): never {
            throw new LogicException('change_claim_history_checkpoint_immutable');
        });
    }
}
