<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceHistoryCheckpoint extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_history_checkpoints';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'backfill_ledger_watermark_id' => 'integer',
            'event_count' => 'integer',
            'event_watermark_id' => 'integer',
            'excluded_legacy_act_count' => 'integer',
            'owner_member_count' => 'integer',
            'owner_member_watermark_id' => 'integer',
            'owner_version_count' => 'integer',
            'owner_version_watermark_id' => 'integer',
            'performance_act_watermark_id' => 'integer',
            'unprovable_legacy_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('production_acceptance_history_checkpoint_immutable');
        });
        static::deleting(static function (): never {
            throw new \LogicException('production_acceptance_history_checkpoint_immutable');
        });
    }
}
