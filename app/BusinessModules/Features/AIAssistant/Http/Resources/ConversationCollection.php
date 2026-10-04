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
            function () use ($actor, $request): array {
                $entries = [];
                foreach ($this->collection as $key => $resource) {
                    $conversation = $resource->resource;
                    $message = $conversation->relationLoaded('lastMessage') ? $conversation->getRelation('lastMessage') : null;
                    if ($message instanceof \App\BusinessModules\Features\AIAssistant\Models\Message) {
                        $entries[$key] = ['conversation' => $conversation, 'message' => $message];
                    }
                }
                $decisions = app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)
                    ->canReadMessages($actor, (int) $actor->current_organization_id, $entries, fresh: false);

                return $this->collection->map(static function ($resource, $key) use ($request, $entries, $decisions): array {
                    $preview = ($decisions[$key] ?? false) ? mb_strimwidth((string) $entries[$key]['message']->content, 0, 140, '...') : null;

                    return $resource->toArrayWithPreview($request, $preview);
                })->all();
            },
            fresh: true,
        );
    }
}
