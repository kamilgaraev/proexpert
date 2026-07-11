<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class EstimateGenerationEvidence extends Model
{
    protected $table = 'estimate_generation_evidence';

    protected $fillable = ['invalidated_at', 'invalidation_reason', 'invalidation_version'];

    protected $casts = [
        'type' => EvidenceType::class,
        'source_type' => EvidenceSourceType::class,
        'locator' => 'array',
        'value' => 'array',
        'confidence' => 'float',
        'invalidated_at' => 'immutable_datetime',
        'invalidation_version' => 'integer',
    ];

    protected function performUpdate(Builder $query): bool
    {
        $mutable = ['invalidated_at', 'invalidation_reason', 'invalidation_version', 'updated_at'];
        if (array_diff(array_keys($this->getDirty()), $mutable) !== []) {
            throw new RuntimeException('estimate_generation.evidence_is_immutable');
        }

        return parent::performUpdate($query);
    }
}
