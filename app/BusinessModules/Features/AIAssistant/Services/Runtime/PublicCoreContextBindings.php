<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use LogicException;

final readonly class PublicCoreContextBindings
{
    public static function coreProfile(GatewayModelProfile $gateway): array
    {
        if (!$gateway->isQualified()) {
            throw new LogicException('model_profile_unqualified');
        }
        $values = $gateway->values();
        $profile = [
            'profileRef' => $values['profileRef'], 'qualification' => 'offline-synthetic',
            'adapterRevision' => $values['adapterRevision'], 'modelId' => $values['modelId'],
            'modelRevision' => $values['modelRevision'], 'tokenizerId' => $values['tokenizerId'],
            'tokenizerRevision' => $values['tokenizerRevision'], 'contextWindow' => $values['contextWindow'],
            'maxOutputTokens' => $values['maxOutputTokens'], 'answerReserve' => $values['answerReserve'],
            'toolReserve' => $values['toolReserve'],
        ];
        AssistantModelContextProfile::resolve($profile['profileRef'], static fn (string $ref): array => $profile);

        return $profile;
    }
}
