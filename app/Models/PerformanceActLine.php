<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\BusinessLogicException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function trans_message;

class PerformanceActLine extends Model
{
    use HasFactory;

    public const TYPE_COMPLETED_WORK = 'completed_work';

    public const TYPE_MANUAL = 'manual';

    protected $fillable = [
        'performance_act_id',
        'completed_work_id',
        'estimate_item_id',
        'estimate_version_id',
        'variation_order_id',
        'line_type',
        'title',
        'unit',
        'quantity',
        'unit_price',
        'amount',
        'currency',
        'manual_reason',
        'basis_snapshot',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'basis_snapshot' => 'array',
    ];

    public function getUnitAttribute(?string $value): ?string
    {
        return trim($value ?? '') !== ''
            ? $value
            : self::unitFromSnapshot($this->basis_snapshot ?? []);
    }

    public static function unitFromSnapshot(array $snapshot): ?string
    {
        if (($snapshot['basis_type'] ?? null) !== 'estimate_version') {
            return null;
        }

        $unit = $snapshot['estimate_item']['measurement_unit'] ?? null;
        if (! is_array($unit)) {
            return null;
        }

        foreach (['short_name', 'name'] as $key) {
            $value = $unit[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            $line->validateLine();
        });
        static::updating(function (self $line): void {
            $line->assertAcceptanceSourceMutable();
        });
        static::deleting(function (self $line): void {
            $line->assertAcceptanceSourceMutable();
        });
    }

    public function performanceAct(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class, 'performance_act_id');
    }

    public function completedWork(): BelongsTo
    {
        return $this->belongsTo(CompletedWork::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class);
    }

    public function assertManualLineAllowed(array $policy): void
    {
        if ($this->line_type !== self::TYPE_MANUAL) {
            return;
        }

        if (! ($policy['allow_manual_lines'] ?? false)) {
            throw new BusinessLogicException(trans_message('act_reports.manual_lines_not_allowed'), 422);
        }

        if (($policy['require_manual_line_reason'] ?? true) && trim((string) $this->manual_reason) === '') {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_reason_required'), 422);
        }
    }

    public function validateLine(): void
    {
        if ($this->line_type === self::TYPE_COMPLETED_WORK && ! $this->completed_work_id) {
            throw new BusinessLogicException(trans_message('act_reports.completed_work_line_required'), 422);
        }

        if (! in_array($this->line_type, [self::TYPE_COMPLETED_WORK, self::TYPE_MANUAL], true)) {
            throw new BusinessLogicException(trans_message('act_reports.invalid_line_type'), 422);
        }
    }

    private function assertAcceptanceSourceMutable(): void
    {
        $accepted = $this->performanceAct()
            ->where(function ($builder): void {
                $builder
                    ->where('is_approved', true)
                    ->orWhereIn('status', [
                        ContractPerformanceAct::STATUS_APPROVED,
                        ContractPerformanceAct::STATUS_SIGNED,
                    ]);
            })
            ->exists();
        if ($accepted) {
            throw new BusinessLogicException(trans_message('act_reports.accepted_act_lines_immutable'));
        }
    }
}
