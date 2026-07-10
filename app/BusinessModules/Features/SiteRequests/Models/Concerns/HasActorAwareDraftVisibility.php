<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Models\Concerns;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

trait HasActorAwareDraftVisibility
{
    public function scopeVisibleToActor(Builder $query, int $actorId): Builder
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Actor ID must be a positive integer.');
        }

        return $query->where(static function (Builder $query) use ($actorId): void {
            $query->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
                ->orWhere('user_id', $actorId);
        });
    }
}
