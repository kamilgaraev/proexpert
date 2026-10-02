<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class ConversationCollection extends ResourceCollection
{
    public $collects = ConversationResource::class;

    public function toArray($request): array
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return parent::toArray($request);
        }

        return app(AssistantDataAccessPolicy::class)->withCurrentChecks(
            $actor,
            (int) $actor->current_organization_id,
            fn (): array => parent::toArray($request),
            fresh: true,
        );
    }
}
