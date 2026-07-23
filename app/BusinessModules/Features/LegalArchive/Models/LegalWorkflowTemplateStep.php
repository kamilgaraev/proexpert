<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalWorkflowTemplateStep extends Model
{
    protected $fillable = [
        'template_id', 'organization_id', 'step_key', 'label', 'sequence', 'parallel_group',
        'required', 'policy_key', 'actor_type', 'actor_reference', 'due_in_hours', 'settings',
    ];

    protected $casts = [
        'sequence' => 'integer', 'required' => 'boolean', 'due_in_hours' => 'integer', 'settings' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (self $step): never => throw new ImmutableDataException(self::class, 'update'));
        self::deleting(static fn (self $step): never => throw new ImmutableDataException(self::class, 'delete'));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LegalWorkflowTemplate::class, 'template_id');
    }
}
