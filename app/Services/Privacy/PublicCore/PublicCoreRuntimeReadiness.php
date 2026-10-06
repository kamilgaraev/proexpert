<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use Closure;
use LogicException;
use Throwable;

final readonly class PublicCoreRuntimeReadiness
{
    public function __construct(
        private RegisteredPublicFixtureRegistry $registry,
        private ?Closure $profileSource = null,
        private ?Closure $qualificationSource = null,
        private ?Closure $tokenizer = null,
    )
    {
    }

    public function qualifiedProfile(): ?GatewayModelProfile
    {
        if ($this->profileSource === null || $this->qualificationSource === null || $this->tokenizer === null) {
            return null;
        }
        try {
            $profile = ($this->profileSource)();
            if (!$profile instanceof GatewayModelProfile || !$profile->isQualified()) {
                return null;
            }
            $proof = ($this->qualificationSource)($profile);
            $keys = ['schemaVersion', 'qualification', 'profileFingerprint', 'registryDigest', 'authorizationFenceEvidenceRef',
                'identityEvidenceRef', 'channelEvidenceRef', 'egressEvidenceRef', 'secretEvidenceRef', 'activationRef'];
            if (!GatewayModelRequest::hasExactKeys($proof, $keys) || $proof['schemaVersion'] !== 'public-core-runtime-proof/1'
                || $proof['profileFingerprint'] !== $profile->fingerprint() || $proof['registryDigest'] !== $this->registry->manifestDigest()
                || $proof['qualification'] !== ($profile->isActualProfile() ? 'actual' : 'local-source-test')) {
                return null;
            }
            foreach (array_slice($keys, 4) as $key) {
                if (!GatewayModelRequest::isReference($proof[$key])) {
                    return null;
                }
            }
            return $profile;
        } catch (Throwable) {
            return null;
        }
    }

    public function registryDigest(): string
    {
        return $this->registry->manifestDigest();
    }

    public function currentProfileFingerprint(): ?string
    {
        return $this->qualifiedProfile()?->fingerprint();
    }

    public function coreProfile(): ?array
    {
        $profile = $this->qualifiedProfile();
        if ($profile === null) {
            return null;
        }
        $v = $profile->values();
        return ['profileRef' => $v['profileRef'], 'qualification' => 'offline-synthetic', 'adapterRevision' => $v['adapterRevision'],
            'modelId' => $v['modelId'], 'modelRevision' => $v['modelRevision'], 'tokenizerId' => $v['tokenizerId'],
            'tokenizerRevision' => $v['tokenizerRevision'], 'contextWindow' => $v['contextWindow'], 'maxOutputTokens' => $v['maxOutputTokens'],
            'answerReserve' => $v['answerReserve'], 'toolReserve' => $v['toolReserve']];
    }

    public function count(string $bytes, array $identity): array
    {
        $profile = $this->qualifiedProfile();
        if ($profile === null || $this->tokenizer === null) {
            throw new LogicException('tokenizer_unqualified');
        }
        $v = $profile->values();
        $expected = array_intersect_key($v, array_flip(['modelId', 'modelRevision', 'tokenizerId', 'tokenizerRevision']));
        $count = ($this->tokenizer)($bytes, $profile);
        if ($identity !== $expected || !GatewayModelRequest::hasExactKeys($count, ['inputTokens', 'tokenizerId', 'tokenizerRevision', 'mappingEvidenceRef'])
            || !is_int($count['inputTokens']) || $count['inputTokens'] < 0 || $count['tokenizerId'] !== $v['tokenizerId']
            || $count['tokenizerRevision'] !== $v['tokenizerRevision'] || $count['mappingEvidenceRef'] !== $v['mappingEvidenceRef']) {
            throw new LogicException('tokenizer_unqualified');
        }
        return $identity + ['tokens' => $count['inputTokens']];
    }

    public function resolve(): array
    {
        $profile = $this->qualifiedProfile();
        $live = $profile !== null && $profile->isActualProfile();
        return [
            'schema_version' => 'public-core-runtime-api/1',
            'mode' => 'public_core_test',
            'data_scope' => 'registered_public_fixture',
            'status' => $live ? 'ready' : 'unavailable',
            'reason_code' => $live ? 'none' : 'runtime_not_activated',
            'source_contract_version' => 'public-core-authority/0.4-candidate',
            'actual_model' => $live ? $profile->values()['modelId'] : null,
            'model_enabled' => $live,
            'capabilities' => ['text' => $live, 'tools' => $live, 'vision' => false],
            'free_input_enabled' => false,
            'uploads_enabled' => false,
            'actions_enabled' => false,
            'private_ready' => false,
            'fixtures' => $this->registry->catalog(),
        ];
    }
}
